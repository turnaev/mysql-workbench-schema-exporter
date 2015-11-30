<?php

namespace MwbExporter;

use MwbExporter\Formatter\FormatterInterface;
use MwbExporter\Model\Document;

/**
 * Class Compiler.
 */
class ValidationCreatetor
{
    use XmlPrettyTrait;

    /**
     * @var string
     */
    private $configDir;

    /**
     * @var string
     */
    private $validationDir;

    private $ignoreFeilds = [
        'realmCode',
        'guid',
        'version',
        'createdAt',
        'createdByUser',
        'createdByPartyCode',
        'changedAt',
        'changedByUser',
        'changedByPartyCode'
    ];

    /**
     * @param string $configDir
     */
    public function __construct($configDir)
    {
        $this->configDir = $configDir;
        $this->validationDir = dirname($configDir).'/validation';
    }

    private function initDom()
    {
        $xml
                = <<<XML
<?xml version="1.0" encoding="UTF-8"?>

<constraint-mapping
        xmlns="http://symfony.com/schema/dic/constraint-mapping"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://symfony.com/schema/dic/constraint-mapping http://symfony.com/schema/dic/services/constraint-mapping-1.0.xsd">
</constraint-mapping>
XML;
        $this->dom = new \DOMDocument();
        $this->dom->loadXML($xml);
    }

    public function create()
    {
        $validationsDir = $this->validationDir;
        @ mkdir($validationsDir);

        $dir = new \DirectoryIterator($this->configDir);
        $files = [];
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot()) {
                $fileName = $fileinfo->getFileName();
                $fileName = preg_replace('/\.orm\.xml$/', '.xml', $fileName);
                $files[$fileinfo->getPathName()] = $fileName;
            }
        }
        asort($files);

        foreach ($files as $modelFilePath => $validationFile) {
            $this->initDom();

            $validationFile = $validationsDir.'/'.$validationFile;

            $model = simplexml_load_file($modelFilePath);

            $root   = $this->dom->documentElement;
            $classE = $this->dom->createElement('class');

            $className = $model->entity->attributes()['name'].'';
            $classE->setAttribute('name', $className);

            $uniqueConstraints = $model->entity->{'unique-constraints'};
            if (count($uniqueConstraints)) {
                foreach ($uniqueConstraints->{'unique-constraint'} as $uniqueConstraint) {
                    $this->addUq($uniqueConstraint, $classE,  $className);
                }
            }

            $fields = static::getSortedFields($model);

            foreach ($fields as $field) {

                if(in_array($field->name, $this->ignoreFeilds)) {
                    continue;
                }

                switch ($field->eType) {
                    case 'field':
                        $this->addField($field, $classE);
                        break;

                    case 'one-to-one':
                        $this->addOneToOne($field, $classE);
                        break;

                    case 'many-to-one':
                        $this->addManyToOne($field, $classE);
                        break;
                }
            }

            $root->appendChild($classE);

            $xml = $this->prettyXml($this->dom->saveXML());

            file_put_contents($validationFile, $xml);
        }
    }

    private function addUq($uniqueConstraint, $classE,  $className)
    {
        //<constraint name="Common\DoctrineBundle\Validator\Constraints\UuidUnique">
        //    <option name="strict">false</option>
        //    <option name="uuidProperty">guid</option>
        //    <option name="message">Vehicle (with guid) already exists.</option>
        //</constraint>
        //<constraint name="Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity">
        //    <option name="fields">
        //        <value>email</value>
        //    </option>
        //    <option name="message">Person (with email) already exists.</option>
        //</constraint>

        $origColumns = $uniqueConstraint->attributes()['columns'];

        if(in_array($origColumns, $this->ignoreFeilds)) {
            return;
        }

        $columns = $uniqueConstraint->attributes()['columns'];
        $columns = explode(',', $columns);
        array_walk($columns, function (&$v) {
            $v = trim($v);
            $v = preg_replace('/_id$/', '', $v);
            $v = preg_replace('/_/', ' ', $v);
            $v = ucwords($v);
            $v = lcfirst($v);
            $v = preg_replace('/\s/', '', $v);
        });

        $constraintE = $this->dom->createElement('constraint');
        if($origColumns == 'guid') {
            $constraintE->setAttribute('name', 'Common\DoctrineBundle\Validator\Constraints\UuidUnique');

            $optionE = $this->dom->createElement('option', 'false');
            $optionE->setAttribute('name', 'strict');
            $constraintE->appendChild($optionE);

            $optionE = $this->dom->createElement('option', $origColumns);
            $optionE->setAttribute('name', 'uuidProperty');
            $constraintE->appendChild($optionE);

        } else {
            $constraintE->setAttribute('name', 'Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity');

            $optionE = $this->dom->createElement('option');
            $optionE->setAttribute('name', 'fields');
            $constraintE->appendChild($optionE);

            foreach ($columns as $column) {
                $valueE = $this->dom->createElement('value', $column);
                $optionE->appendChild($valueE);
            }
        }

        $objName = 'Object';
        if (preg_match('/[^\\\]*?$/', $className, $m)) {
            $objName = $m[0];
        }

        $columns = implode(', ', $columns);
        $optionE = $this->dom->createElement('option', "$objName (with $columns) already exists.");
        $optionE->setAttribute('name', 'message');
        $constraintE->appendChild($optionE);

        $classE->appendChild($constraintE);

    }

    private function addField($field, $classE)
    {
        $propertyE = $this->dom->createElement('property');
        $fieldName = $field->name;
        $propertyE->setAttribute('name', $fieldName);

        if ($field->nullable == 'false' && !in_array($field->type, ['boolean', 'bool'])) {
            $constraintE = $this->dom->createElement('constraint');
            $constraintE->setAttribute('name', 'NotBlank');
            $propertyE->appendChild($constraintE);

            $classE->appendChild($propertyE);
        }

        if (in_array($field->type, ['dateinterval', 'date', 'datetime', 'datetime_with_millisecond'])) {
            $constraintE = $this->dom->createElement('constraint');

            if ($field->type == 'dateinterval') {

                $constraintE->setAttribute('name', 'DateInterval');

            } elseif (in_array($field->type, ['datetime', 'datetime_with_millisecond'])) {

                $constraintE->setAttribute('name', 'DateTime');

            } elseif ($field->type == 'date') {

                $constraintE->setAttribute('name', 'Date');
            }

            $propertyE->appendChild($constraintE);

            $classE->appendChild($propertyE);
        }

        if (in_array($field->type, ['decimal', 'float', 'boolean', 'integer'])) {
            $type    = $field->type;
            $typeMap = [
                'decimal' => 'float',
                'float'   => 'float',
                'boolean' => 'bool',
                'integer' => 'integer',
            ];

            $constraintE = $this->dom->createElement('constraint');
            $constraintE->setAttribute('name', 'Type');

            $optionE = $this->dom->createElement('option', $typeMap[$type]);
            $optionE->setAttribute('name', 'type');
            $constraintE->appendChild($optionE);

            $propertyE->appendChild($constraintE);

            $classE->appendChild($propertyE);
        }

        if (isset($field->length) && is_numeric($field->length)) {
            if ($field->type != 'dateinterval') {
                $constraintE = $this->dom->createElement('constraint');
                $constraintE->setAttribute('name', 'Length');

                $optionE = $this->dom->createElement('option', $field->length);
                $optionE->setAttribute('name', 'max');

                $constraintE->appendChild($optionE);

                $propertyE->appendChild($constraintE);

                $classE->appendChild($propertyE);
            }
        }
    }

    private function addOneToOne($field, $classE)
    {
        $propertyE = $this->dom->createElement('property');
        $fieldType = '\\'.$field->orig->attributes()['target-entity'];
        $fieldType = preg_replace('/^\\\/', '\\', $fieldType);
        $fieldName = $field->field;

        $propertyE->setAttribute('name', $fieldName);

        $joinFieldAttrs = $field->orig->{'join-columns'}->{'join-column'}->attributes();

        if ($joinFieldAttrs['nullable'] == 'false') {
            $constraintE = $this->dom->createElement('constraint');
            $constraintE->setAttribute('name', 'NotBlank');
            $propertyE->appendChild($constraintE);
        }

        $constraintE = $this->dom->createElement('constraint');
        $constraintE->setAttribute('name', 'Type');

        $optionE = $this->dom->createElement('option', $fieldType);
        $optionE->setAttribute('name', 'type');
        $constraintE->appendChild($optionE);

        $propertyE->appendChild($constraintE);

        $classE->appendChild($propertyE);

        $classE->appendChild($propertyE);
    }

    private function addManyToOne($field, $classE)
    {
        $propertyE = $this->dom->createElement('property');
        $fieldType = '\\'.$field->orig->attributes()['target-entity'];

        $fieldType = preg_replace('/^\\\/', '\\', $fieldType);
        $fieldName = $field->name;

        $propertyE->setAttribute('name', $fieldName);

        $joinFieldAttrs = $field->orig->{'join-columns'}->{'join-column'}->attributes();

        if ($joinFieldAttrs['nullable'] == 'false') {
            $constraintE = $this->dom->createElement('constraint');
            $constraintE->setAttribute('name', 'NotBlank');
            $propertyE->appendChild($constraintE);
        }

        $constraintE = $this->dom->createElement('constraint');
        $constraintE->setAttribute('name', 'Type');

        $optionE = $this->dom->createElement('option', $fieldType);
        $optionE->setAttribute('name', 'type');
        $constraintE->appendChild($optionE);

        $propertyE->appendChild($constraintE);

        $classE->appendChild($propertyE);
    }

    private static function getSortedFields($model)
    {
        $fields = [];
        foreach(['field', 'one-to-one', 'many-to-one'] as $type) {

            $tmpFields = [];

            foreach($model->entity->{$type} as $r => $a) {
                $orig = $a;
                $a = (array)$a->attributes();
                $a = $a["@attributes"];

                $a['eType'] = $type;
                if(isset($a['field'])) {
                    $a['name'] = $a['field'];
                }
                $a['orig'] = $orig;

                $tmpFields[]= (object)$a;
            }

            $fields = array_merge($fields, $tmpFields);
        }

        usort($fields, function($a, $b) {
            $a = $a->name;
            $b = $b->name;
            if($a == $b) {
                return 0;
            }

            return ($a > $b) ? 1 : -1;
        });

        return $fields;
    }
}
