<?php

namespace Ubiquity\orm\traits;

use Ubiquity\orm\OrmUtils;
use Ubiquity\orm\parser\ManyToManyParser;
use Ubiquity\orm\parser\ConditionParser;

trait DAORelationsTrait {
	abstract protected static function _getAll($className, ConditionParser $conditionParser, $included=true,$useCache=NULL);
	
	private static function _affectsRelationObjects($manyToOneQueries,$oneToManyQueries,$manyToManyParsers,$objects,$included,$useCache){
		if(\sizeof($manyToOneQueries)>0){
			self::_affectsObjectsFromArray($manyToOneQueries, $objects,$included, function($object,$member,$manyToOneObjects,$fkField){
				self::affectsManyToOneFromArray($object,$member,$manyToOneObjects,$fkField);
			});
		}
		
		if(\sizeof($oneToManyQueries)>0){
			self::_affectsObjectsFromArray($oneToManyQueries, $objects,$included, function($object,$member,$relationObjects,$fkField){
				self::affectsOneToManyFromArray($object,$member,$relationObjects,$fkField);
			});
		}
		
		if(\sizeof($manyToManyParsers)>0){
			self::_affectsManyToManyObjectsFromArray($manyToManyParsers, $objects,$included,$useCache);
		}
	}
	
	private static function affectsManyToOneFromArray($object,$member,$manyToOneObjects,$fkField){
		$class=\get_class($object);
		if(isset($object->$fkField)){
			$value=$manyToOneObjects[$object->$fkField];
			self::setToMember($member, $object, $value, $class, "getManyToOne");
		}
	}
	
	private static function _affectsObjectsFromArray($queries,$objects,$included,$affectsCallback,$useCache=NULL){
		$includedNext=false;
		foreach ($queries as $key=>$cParser){
			list($class,$member,$fkField)=\explode("|", $key);
			if(is_array($included)){
				$includedNext=self::_getIncludedNext($included, $member);
			}
			$cParser->compileParts();
			$relationObjects=self::_getAll($class,$cParser,$includedNext,$useCache);
			foreach ($objects as $object){
				$affectsCallback($object, $member,$relationObjects,$fkField);
			}
		}
	}
	
	private static function _affectsManyToManyObjectsFromArray($parsers,$objects,$included,$useCache=NULL){
		$includedNext=false;
		foreach ($parsers as $key=>$parser){
			list($class,$member,$inversedBy)=\explode("|", $key);
			if(is_array($included)){
				$includedNext=self::_getIncludedNext($included, $member);
			}
			$cParser=$parser->generateConditionParser();
			$relationObjects=self::_getAll($class,$cParser,$includedNext,$useCache);
			$oClass=get_class(reset($objects));
			foreach ($objects as $object){
				$ret=self::getManyToManyFromArrayIds($object, $relationObjects, $member);
				self::setToMember($member, $object, $ret, $oClass, "getManyToMany");
			}
		}
	}
	
	private static function _getIncludedNext($included,$member){
		return (isset($included[$member]))?(is_bool($included[$member])?$included[$member]:[$included[$member]]):false;
	}
	
	
	
	private static function getManyToManyFromArrayIds($object, $relationObjects, $member){
		$iMember="_".$member;
		$ids=$object->$iMember;
		$ret=[];
		foreach ( $relationObjects as $targetEntityInstance ) {
			$id=OrmUtils::getFirstKeyValue($targetEntityInstance);
			if (array_search($id, $ids)!==false) {
				array_push($ret, $targetEntityInstance);
			}
		}
		unset($object->$iMember);
		return $ret;
	}
	
	/**
	 * Prepares members associated with $instance with a ManyToMany type relationship
	 * @param $ret array of sql conditions
	 * @param object $instance
	 * @param string $member Member on which a ManyToMany annotation must be present
	 * @param array $annot used internally
	 */
	private static function prepareManyToMany(&$ret,$instance, $member, $annot=null) {
		$class=get_class($instance);
		$iMember="_".$member;
		if (!isset($annot))
			$annot=OrmUtils::getAnnotationInfoMember($class, "#ManyToMany", $member);
			if ($annot !== false) {
				$key=$annot["targetEntity"]."|".$member."|".$annot["inversedBy"];
				if(!isset($ret[$key])){
					$parser=new ManyToManyParser($instance, $member);
					$parser->init($annot);
					$ret[$key]=$parser;
				}
				$accessor="get" . ucfirst($ret[$key]->getMyPk());
				if(method_exists($instance, $accessor)){
					$fkv=$instance->$accessor();
					$result=self::$db->prepareAndFetchColumn($ret[$key]->getJoinSQL(),[$fkv]);
					$ret[$key]->addValues($result);
					$instance->$iMember=$result;
				}
			}
	}
	
	/**
	 * Prepares members associated with $instance with a oneToMany type relationship
	 * @param $ret array of sql conditions
	 * @param object $instance
	 * @param string $member Member on which a OneToMany annotation must be present
	 * @param array $annot used internally
	 */
	private static function prepareOneToMany(&$ret,$instance, $member, $annot=null) {
		$class=get_class($instance);
		if (!isset($annot))
			$annot=OrmUtils::getAnnotationInfoMember($class, "#oneToMany", $member);
			if ($annot !== false) {
				$fkAnnot=OrmUtils::getAnnotationInfoMember($annot["className"], "#joinColumn", $annot["mappedBy"]);
				if ($fkAnnot !== false) {
					$fkv=OrmUtils::getFirstKeyValue($instance);
					$key=$annot["className"]."|".$member."|".$annot["mappedBy"];
					if(!isset($ret[$key])){
						$ret[$key]=new ConditionParser();
					}
					$ret[$key]->addPart($fkAnnot["name"] . "= ?",$fkv);
				}
			}
	}
	
	/**
	 * Prepares members associated with $instance with a manyToOne type relationship
	 * @param $ret array of sql conditions
	 * @param mixed $value
	 * @param string $fkField
	 * @param array $annotationArray
	 */
	private static function prepareManyToOne(&$ret, $value, $fkField,$annotationArray) {
		$member=$annotationArray["member"];
		$fk=OrmUtils::getFirstKey($annotationArray["className"]);
		$key=$annotationArray["className"]."|".$member."|".$fkField;
		if(!isset($ret[$key])){
			$ret[$key]=new ConditionParser();
		}
		$ret[$key]->addPart($fk . "= ?",$value);
	}
	
	private static function getIncludedForStep($included){
		if(is_bool($included)){
			return $included;
		}
		$ret=[];
		if(is_array($included)){
			foreach ($included as $index=>&$includedMember){
				if(is_array($includedMember)){
					foreach ($includedMember as $iMember){
						self::parseEncludeMember($ret, $iMember);
					}
				}else{
					self::parseEncludeMember($ret, $includedMember);
				}
			}
		}
		
		return $ret;
	}
	
	private static function parseEncludeMember(&$ret,$includedMember){
		$array=explode(".", $includedMember);
		$member=array_shift($array);
		if(sizeof($array)>0){
			$newValue=implode(".", $array);
			if($newValue==='*'){
				$newValue=true;
			}
			if(isset($ret[$member])){
				if(!is_array($ret[$member])){
					$ret[$member]=[$ret[$member]];
				}
				$ret[$member][]=$newValue;
			}else{
				$ret[$member]=$newValue;
			}
		}else{
			if(isset($member) && ""!=$member){
				$ret[$member]=false;
			}else{
				return;
			}
		}
	}
	
	private static function getInvertedJoinColumns($included,&$invertedJoinColumns){
		foreach ($invertedJoinColumns as $column=>&$annot){
			$member=$annot["member"];
			if(isset($included[$member])===false){
				unset($invertedJoinColumns[$column]);
			}
		}
	}
	
	private static function getToManyFields($included,&$toManyFields){
		foreach ($toManyFields as $member=>&$annot){
			if(isset($included[$member])===false){
				unset($toManyFields[$member]);
			}
		}
	}
	
	private static function _initRelationFields($included,$metaDatas,&$invertedJoinColumns,&$oneToManyFields,&$manyToManyFields){
		if (isset($metaDatas["#invertedJoinColumn"])){
			$invertedJoinColumns=$metaDatas["#invertedJoinColumn"];
		}
		if (isset($metaDatas["#oneToMany"])) {
			$oneToManyFields=$metaDatas["#oneToMany"];
		}
		if (isset($metaDatas["#manyToMany"])) {
			$manyToManyFields=$metaDatas["#manyToMany"];
		}
		if(is_array($included)){
			if(isset($invertedJoinColumns)){
				self::getInvertedJoinColumns($included, $invertedJoinColumns);
			}
			if(isset($oneToManyFields)){
				self::getToManyFields($included, $oneToManyFields);
			}
			if(isset($manyToManyFields)){
				self::getToManyFields($included, $manyToManyFields);
			}
		}
	}
}