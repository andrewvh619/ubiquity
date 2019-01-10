<?php

namespace Ubiquity\contents\validation;

use Ubiquity\cache\CacheManager;
use Ubiquity\log\Logger;
use Ubiquity\contents\validation\validators\multiples\LengthValidator;
use Ubiquity\contents\validation\validators\multiples\IdValidator;
use Ubiquity\contents\validation\validators\basic\NotNullValidator;
use Ubiquity\contents\validation\validators\basic\NotEmptyValidator;
use Ubiquity\contents\validation\validators\comparison\EqualsValidator;
use Ubiquity\contents\validation\validators\basic\TypeValidator;
use Ubiquity\contents\validation\validators\comparison\GreaterThanValidator;
use Ubiquity\contents\validation\validators\comparison\LessThanValidator;
use Ubiquity\contents\validation\validators\basic\IsNullValidator;
use Ubiquity\contents\validation\validators\basic\IsEmptyValidator;
use Ubiquity\contents\validation\validators\basic\IsTrueValidator;
use Ubiquity\contents\validation\validators\basic\IsFalseValidator;
use Ubiquity\contents\validation\validators\strings\RegexValidator;
use Ubiquity\contents\validation\validators\strings\EmailValidator;
use Ubiquity\contents\validation\validators\strings\UrlValidator;
use Ubiquity\contents\validation\validators\strings\IpValidator;
use Ubiquity\contents\validation\validators\comparison\RangeValidator;
use Ubiquity\contents\validation\validators\comparison\GreaterThanOrEqualValidator;
use Ubiquity\contents\validation\validators\comparison\LessThanOrEqualValidator;
use Ubiquity\contents\validation\validators\dates\DateValidator;
use Ubiquity\contents\validation\validators\dates\DateTimeValidator;
use Ubiquity\contents\validation\validators\dates\TimeValidator;
use Ubiquity\contents\validation\validators\basic\IsBooleanValidator;
use Ubiquity\cache\objects\SessionCache;

/**
 * Validators manager
 * @author jcheron <myaddressmail@gmail.com>
 * @version 1.0.0
 */
class ValidatorsManager {
	protected static $instanceValidators=[];
	protected static $cache;
	
	public static function start(){
		self::$cache=new SessionCache();
	}
	
	public static $validatorTypes=[
			"notNull"=>NotNullValidator::class,
			"isNull"=>IsNullValidator::class,
			"notEmpty"=>NotEmptyValidator::class,
			"isEmpty"=>IsEmptyValidator::class,
			"isTrue"=>IsTrueValidator::class,
			"isFalse"=>IsFalseValidator::class,
			"isBool"=>IsBooleanValidator::class,
			"equals"=>EqualsValidator::class,
			"type"=>TypeValidator::class,
			"greaterThan"=>GreaterThanValidator::class,
			"greaterThanOrEqual"=>GreaterThanOrEqualValidator::class,
			"lessThan"=>LessThanValidator::class,
			"lessThanOrEqual"=>LessThanOrEqualValidator::class,
			"length"=>LengthValidator::class,
			"id"=>IdValidator::class,
			"regex"=>RegexValidator::class,
			"email"=>EmailValidator::class,
			"url"=>UrlValidator::class,
			"ip"=>IpValidator::class,
			"range"=>RangeValidator::class,
			"date"=>DateValidator::class,
			"dateTime"=>DateTimeValidator::class,
			"time"=>TimeValidator::class
			
	];
	
	protected static $key="contents/validators/";
	
	/**
	 * Registers a validator type for using with @validator annotation
	 * @param string $type
	 * @param string $validatorClass
	 */
	public static function registerType($type,$validatorClass){
		self::$validatorTypes[$type]=$validatorClass;
	}
	
	/**
	 * Parses models and save validators in cache
	 * to use in dev only
	 * @param array $config
	 */
	public static function initModelsValidators(&$config){
		$models=CacheManager::getModels($config,true);
		foreach ($models as $model){
			$parser=new ValidationModelParser();
			$parser->parse($model);
			$validators=$parser->getValidators();
			if(sizeof($validators)>0){
				self::store($model, $parser->__toString());
			}
		}
	}
	
	protected static function store($model,$validators){
		CacheManager::$cache->store(self::getModelCacheKey($model), $validators);
	}
	
	protected static function fetch($model){
		$key=self::getModelCacheKey($model);
		if(CacheManager::$cache->exists($key)){
			return CacheManager::$cache->fetch($key);
		}
		return [];
	}
	
	protected static function getGroupArrayValidators(array $validators,$group){
		$result=[];
		foreach ($validators as $member=>$validators){
			$filteredValidators=self::getGroupMemberValidators($validators, $group);
			if(sizeof($filteredValidators)){
				$result[$member]=$filteredValidators;
			}
		}
		return $result;
	}
	
	protected static function getGroupMemberValidators(array $validators,$group){
		$result=[];
		foreach ($validators as $validator){
			if(isset($validator["group"]) && $validator["group"]===$group){
				$result[]=$validator;
			}
		}
		return $result;
	}
	
	private static function getCacheValidators($instance,$group=""){
		if(isset(self::$cache)){
			$key=self::getHash(get_class($instance).$group);
			if(self::$cache->exists($key)){
				return self::$cache->fetch($key);
			}
		}
		return false;
	}
	
	/**
	 * Validates an instance
	 * @param object $instance
	 * @param string $group
	 * @return \Ubiquity\contents\validation\validators\ConstraintViolation[]
	 */
	public static function validate($instance,$group=""){
		$cache=self::getCacheValidators($instance,$group);
		if($cache!==false){
			return self::validateFromCache_($instance,$cache);
		}
		$members=self::fetch(get_class($instance));
		if($group!==""){
			$members=self::getGroupArrayValidators($members, $group);
		}
		return self::validate_($instance,$members);
	}
	
	/**
	 * Validates an array of objects
	 * @param array $instances
	 * @param string $group
	 * @return \Ubiquity\contents\validation\validators\ConstraintViolation[]
	 */
	public static function validateInstances($instances,$group=""){
		if(sizeof($instances)>0){
			$instance=reset($instances);
			$cache=self::getCacheValidators($instance,$group);
			if($cache===false){
				$class=get_class($instance);
				$members=self::fetch($class);
				self::initInstancesValidators($instance, $members,$group);
				$cache=self::$instanceValidators[$class];
			}
			return self::validateInstances_($instances,$cache);
		}
		return [];
	}
	
	public static function clearCache($model=null,$group=""){
		if(isset(self::$cache)){
			if(isset($model)){
				$key=self::getHash($model.$group);
				self::$cache->remove($key);
			}else{
				self::$cache->clear();
			}
		}
	}
	
	protected static function validateInstances_($instances,$members){
		$result=[];
		foreach ($instances as $instance){
			foreach ($members as $accessor=>$validators){
				foreach ($validators as $validator){
					$valid=$validator->validate_($instance->$accessor());
					if($valid!==true){
						$result[]=$valid;
					}
				}
			}
		}
		return $result;
	}
	
	protected static function validate_($instance,$members){
		$result=[];
		foreach ($members as $member=>$validators){
			$accessor="get".ucfirst($member);
			if(method_exists($instance, $accessor)){
				foreach ($validators as $validator){
					$validatorInstance=self::getValidatorInstance($validator["type"]);
					if($validatorInstance!==false){
						$validatorInstance->setValidationParameters($member,$validator["constraints"],@$validator["severity"],@$validator["message"]);
						$valid=$validatorInstance->validate_($instance->$accessor());
						if($valid!==true){
							$result[]=$valid;
						}
					}
				}
			}
		}
		return $result;
	}
	
	protected static function validateFromCache_($instance,$members){
		$result=[];
		foreach ($members as $accessor=>$validators){
			foreach ($validators as $validatorInstance){
				$valid=$validatorInstance->validate_($instance->$accessor());
				if($valid!==true){
					$result[]=$valid;
				}
			}
		}
		return $result;
	}
	
	/**
	 * Initializes the cache (SessionCache) for the class of înstance
	 * @param object $instance
	 * @param string $group
	 */
	public static function initCacheInstanceValidators($instance,$group=""){
		$class=get_class($instance);
		$members=self::fetch($class);
		self::initInstancesValidators($instance, $members,$group);
	}
	
	protected static function initInstancesValidators($instance,$members,$group=""){
		$class=get_class($instance);
		$result=[];
		foreach ($members as $member=>$validators){
			$accessor="get".ucfirst($member);
			if(method_exists($instance, $accessor)){
				foreach ($validators as $validator){
					$validatorInstance=self::getValidatorInstance($validator["type"]);
					if($validatorInstance!==false){
						$validatorInstance->setValidationParameters($member,$validator["constraints"],@$validator["severity"],@$validator["message"]);
						if($group==="" || (isset($validator["group"]) && $validator["group"]===$group)){
							self::$instanceValidators[$class][$accessor][]=$validatorInstance;
							$result[$accessor][]=$validatorInstance;
						}
					}
				}
			}
		}
		self::$cache->store(self::getHash($class.$group), $result);
	}
	
	protected static function getHash($class){
		return hash("sha1", $class);
	}
	
	protected static function getModelCacheKey($classname){
		return self::$key.\str_replace("\\", \DS, $classname);
	}
	
	protected static function getValidatorInstance($type){
		if(isset(self::$validatorTypes[$type])){
			$class=self::$validatorTypes[$type];
			return new $class();
		}else{
			Logger::warn("validation", "Validator ".$type." does not exists!");
			return false;
		}
	}
}
