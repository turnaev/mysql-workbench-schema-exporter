<?php

namespace MwbExporter;

use MwbExporter\Formatter\FormatterInterface;
use MwbExporter\Model\Document;

class Compiler
{
    /**
     * @var FormatterInterface
     */
    private $formatter;

    /**
     * @var Cocument
     */
    private $document;

    /**
     * @param FormatterInterface $formatter
     * @param Document           $document
     */
    public function __construct(FormatterInterface $formatter, Document $document)
    {
        $this->document  = $document;
        $this->formatter = $formatter;
    }

    public function preCompileModels()
    {
        if ($this->formatter->getFileExtension() == 'php') {

            $fromDir = $this->document->getWriter()->getStorage()->getResult();
            $toDir = dirname($this->document->getWriter()->getStorage()->getResult());

            $dir = new \DirectoryIterator($this->document->getWriter()->getStorage()->getResult());
            foreach ($dir as $fileinfo) {

                $fromFile = $fromDir . '/' . $fileinfo->getFilename();
                $toFile = $toDir . '/' . $fileinfo->getFilename();

                if (!$fileinfo->isDot()) {

                    if ($fileinfo->getExtension() == 'php') {

                        $this->createWorkModelClass($fromFile, $toFile);

                    } else if ($fileinfo->getExtension() == 'bak') {
                        unlink($fromFile);
                    }
                }
            }
        }
    }

    public function postCompileModels()
    {
        if ($this->formatter->getFileExtension() == 'php') {

            $modelDir = $this->document->getWriter()->getStorage()->getResult();
            $dir = new \DirectoryIterator($this->document->getWriter()->getStorage()->getResult());

            foreach ($dir as $fileinfo) {

                if (!$fileinfo->isDot()) {

                    $modelFile = $modelDir . '/' . $fileinfo->getFilename();

                    if ($fileinfo->getExtension() == 'php') {

                        $this->changeModelClassDef($modelFile);

                    } else if ($fileinfo->getExtension() == 'bak') {
                        unlink($modelFile);
                    }
                }
            }

            $configDir = dirname(dirname($this->document->getWriter()->getStorage()->getResult())).'/Resources/config';
            $configFromDirXml = $configDir.'/doctrine-xml';
            $configToDirXml = $configDir.'/doctrine';

            if(is_dir($configFromDirXml)) {

                $this->createDir($configToDirXml);

                $dir = new \DirectoryIterator($configFromDirXml);
                foreach($dir as $fileinfo) {

                    if (!$fileinfo->isDot()) {

                        $fromXmlFile = $configFromDirXml . '/' . $fileinfo->getFilename();
                        $this->changeMetaModel($fromXmlFile, $configToDirXml);
                    }
                }
            }
            $this->finalCompileModels();
        }
    }

    private function changeNamenamespaceModel(\RecursiveDirectoryIterator $dir, $namenamespaceFrom, $namenamespaceTo) {

        $namenamespaceFromRgx = preg_quote($namenamespaceFrom, "%");

        foreach($dir as $fileinfo) {
            if (!in_array($fileinfo->getFilename() , ['.', '..'])) {

                if(!$dir->hasChildren()) {

                    $content = file_get_contents($fileinfo->getPathname());
                    $content = preg_replace("%$namenamespaceFromRgx%", $namenamespaceTo, $content);
                    file_put_contents($fileinfo->getPathname(), $content);

                } else {
                    $dirInner = $dir->getChildren();
                    $this->changeNamenamespaceModel($dirInner, $namenamespaceFrom, $namenamespaceTo);
                }
            }
        }
    }

    private function finalCompileModels()
    {
        $namenamespaceTo = $this->formatter->getRegistry()->config->get(FormatterInterface::CFG_BUNDELE_NAMESPACE_TO);
        $modeDir = dirname($this->document->getWriter()->getStorage()->getResult());
        $metaModeDir = dirname(dirname($this->document->getWriter()->getStorage()->getResult())).'/Resources/config/doctrine';

        if($namenamespaceTo) {

            $namenamespaceFrom = $this->formatter->getRegistry()->config->get(FormatterInterface::CFG_BUNDELE_NAMESPACE);


            $dir = new \RecursiveDirectoryIterator($modeDir);
            $this->changeNamenamespaceModel($dir, $namenamespaceFrom, $namenamespaceTo);


            $dir = new \RecursiveDirectoryIterator($metaModeDir);
            $this->changeNamenamespaceModel($dir, $namenamespaceFrom, $namenamespaceTo);
        }

        $this->createValidators($metaModeDir);
    }

    private function changeModelClassDef($modelFile)
    {
        $fromFileContent = file_get_contents($modelFile);
        $toFileContent = preg_replace('/(class) ([^\s]+)/', 'abstract \1 \2', $fromFileContent);
        file_put_contents($modelFile, $toFileContent);

        $this->removeORMAnatation($modelFile);
    }

    private function changeMetaModel($fromXmlFile, $configToDirXml) {

        $fromFileContent = file_get_contents($fromXmlFile);
        $toFileContent = preg_replace('/Model\\\/', '', $fromFileContent);
        $toFileContent = preg_replace('/nullable=""/', 'nullable="false"', $toFileContent);
        $toFileContent = preg_replace('/nullable="1"/', 'nullable="true"', $toFileContent);
	$toFileContent = preg_replace('/ precision="0" scale="0"/', '', $toFileContent);

        if(preg_match('/.*Model\.(.*)/', pathinfo($fromXmlFile)['filename'], $m)) {
            $toXmlFile = $configToDirXml . '/' . $m[1].'.xml';
            file_put_contents($toXmlFile, $toFileContent);
        }
    }

    private function createWorkModelClass($fromFile, $toFile)
    {
        $fromFileContent = file_get_contents($fromFile);

        $toFileContent = preg_replace('/Model\\\/', '', $fromFileContent);
        $toFileContent = preg_replace('/\\\Model/', '', $toFileContent);

        $toFileContent = preg_replace('/!!!WARNING.*/', 'please add your code' , $toFileContent);
        $toFileContent = preg_replace('/(class) ([^\s]+)/', '\1 \2 extends Model\\\\\2', $toFileContent);

        file_put_contents($toFile, $toFileContent);

        $this->removeUse($toFile);
        $this->removeORMAnatation($toFile);
        $this->removeClassBody($toFile);

        $this->createRepository($toFile, $this->formatter->getRegistry()->config->get(FormatterInterface::CFG_BASE_NAMESPASE));
    }

    private function createDir($dirName)
    {
        if(!is_dir($dirName)) {
            mkdir($dirName);
        }
    }

    private function createValidators($configDir)
    {
        $validationFile = dirname($configDir).'/validation.xml';
        $dir = new \DirectoryIterator($configDir);
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>

<constraint-mapping xmlns="http://symfony.com/schema/dic/constraint-mapping"
xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
xsi:schemaLocation="http://symfony.com/schema/dic/constraint-mapping http://symfony.com/schema/dic/services/constraint-mapping-1.0.xsd">
</constraint-mapping>
XML;

        $dom = new \DOMDocument();

        $dom->loadXML($xml);

        foreach($dir as $fileinfo) {

            if (!$fileinfo->isDot()) {

                $model = simplexml_load_file($fileinfo->getPathName());

                $root = $dom->documentElement;
                $classE = $dom->createElement('class');

                $className = $model->entity->attributes()['name'].'';
                $classE->setAttribute('name', $className);

                $constrains = array();
                foreach($model->entity->field as $field) {

                    $constrainsFields = array();

                    $fieldAttrs = $field->attributes();

                    $propertyE = $dom->createElement('property');
                    $fieldName = $fieldAttrs['name'].'';
                    $propertyE->setAttribute('name', $fieldName);

                    if($fieldAttrs['nullable'] == 'false') {

                        $constraintE = $dom->createElement('constraint');
                        $constraintE->setAttribute('name', 'NotBlank');
                        $propertyE->appendChild($constraintE);

                        $classE->appendChild($propertyE);

                        $constrainsFields[] = $constrains[] = $constraintE;
                    }

                    if($fieldAttrs['type'] == 'datetime') {

                        $constraintE = $dom->createElement('constraint');
                        $constraintE->setAttribute('name', 'DateTime');
                        $propertyE->appendChild($constraintE);

                        $classE->appendChild($propertyE);

                        $constrainsFields[] = $constrains[] = $constraintE;
                    }

                    if(in_array($fieldAttrs['type'].'', ['decimal', 'fload', 'boolean', 'integer'])) {

                        $type = $fieldAttrs['type'].'';
                        $typeMap = [
                            'decimal' => 'float',
                            'fload'   => 'float',
                            'boolean' => 'bool',
                            'integer' => 'integer',
                        ];

                        $constraintE = $dom->createElement('constraint');
                        $constraintE->setAttribute('name', 'Type');

                        $optionE = $dom->createElement('option');
                        $optionE->setAttribute('name', $typeMap[$type]);
                        $constraintE->appendChild($optionE);

                        $propertyE->appendChild($constraintE);

                        $classE->appendChild($propertyE);

                        $constrainsFields[] = $constrains[] = $constraintE;
                    }

                    if(is_numeric($fieldAttrs['length'].'')) {

                        $constraintE = $dom->createElement('constraint');
                        $constraintE->setAttribute('name', 'Length');

                        $optionE = $dom->createElement('option', $fieldAttrs['length'].'');
                        $optionE->setAttribute('name', 'max');

                        $constraintE->appendChild($optionE);

                        $propertyE->appendChild($constraintE);

                        $classE->appendChild($propertyE);

                        $constrainsFields[] = $constrains[] = $constraintE;
                    }
                }

                if(count($constrains)) {
                    $root->appendChild($classE);
                }
            }
        }

        $dom = $this->prettyDomXml($dom);
        $dom->save($validationFile);
    }

    private function prettyDomXml($domIn)
    {
        $domOut = new \DOMDocument();
        $domOut->preserveWhiteSpace = false;
        $domOut->formatOutput = true;

        $xml = $domIn->saveXML();
        $domOut->loadXML($xml);

        return $domOut;
    }

    private function removeORMAnatation($file)
    {
        $toFileContent = file($file);
        foreach ($toFileContent as $k => $line) {

            if (preg_match('/\s+\*\s+@ORM/', $line, $m) ||
                preg_match('/\s+as\s+ORM/', $line, $m)) {

                unset($toFileContent[$k]);
            }
        }
        $toFileContent = join('', $toFileContent);
        file_put_contents($file, $toFileContent);
    }

    private function createRepository($eFile, $baseNamespace='VN')
    {
        $pathinfo = pathinfo($eFile);
        $className = $pathinfo['filename'] . 'Repository';
        $repoDir = $pathinfo['dirname'] . '/Repository';
        $repoFile = $repoDir. '/' . $className . '.php';

        $toFileContent = file($eFile);

        $namespace = null;
        foreach ($toFileContent as $line) {
            if (preg_match('/^namespace\s/', $line, $m)) {

                $namespace = preg_replace('/namespace\s(.*);/', '\1', $line);
                $namespace = trim($namespace);
                break;
            }
        }

        $repositoryClass = <<<PHP
<?php
/**
 * please add your code
 */

namespace ${namespace}\Repository;

use ${baseNamespace}\CoreBundle\Entity\Repository\BaseRepository;

class ${className} extends BaseRepository
{
}
PHP;

        $this->createDir($repoDir);

        file_put_contents($repoFile, $repositoryClass);
    }

    private function removeClassBody($file)
    {
        $toFileContent = array();
        $toFileContentTmp = file($file);

        foreach ($toFileContentTmp as $k => $line) {

            $toFileContent[] = $line;
            if (preg_match('/^class\s/', $line, $m)) {
                $toFileContent[] = "{\n\n";
                $toFileContent[] = "}";
                break;
            }
        }
        $toFileContent = join('', $toFileContent);
        file_put_contents($file, $toFileContent);
    }

    private function removeUse($file)
    {
        $toFileContent = file($file);

        foreach ($toFileContent as $k => $line) {
            if (preg_match('/^use\s/', $line, $m)) {
                unset($toFileContent[$k]);
            }
        }
        $toFileContent = join('', $toFileContent);
        file_put_contents($file, $toFileContent);
    }
}