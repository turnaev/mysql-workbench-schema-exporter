<?php

namespace MwbExporter;

use MwbExporter\Formatter\FormatterInterface;
use MwbExporter\Model\Document;

/**
 * Class Compiler.
 */
class Compiler
{
    use XmlPrettyTrait;

    /**
     * @var FormatterInterface
     */
    private $formatter;

    /**
     * @var sting
     */
    private $workDir;

    /**
     * @var array
     */
    private $quoteWords = [
        'order',
    ];

    /**
     * @param FormatterInterface $formatter
     * @param Document           $workDir
     */
    public function __construct(FormatterInterface $formatter, $workDir)
    {
        $this->workDir  = $workDir;
        $this->formatter = $formatter;
    }

    /**
     *
     */
    public function preCompileModels()
    {
        $dirs = [];
        if ($this->formatter->getFileExtension() == 'php') {

            $fromDir = $this->workDir;

            $toDir   = dirname($fromDir);

            foreach (new \DirectoryIterator($fromDir) as $fileinfo) {

                if (!$fileinfo->isDot()) {

                    $fromFile = $fromDir.'/'.$fileinfo->getFilename();

                    $content = file_get_contents($fromFile);
                    if(preg_match('#namespace (.*?);#', $content, $m)) {

                        $namespace = preg_replace('/\\\/', '/', $m[1]);

                        if(false === strpos($namespace, $fromFile)) {

                            $newFromFile = preg_replace('#src/.*?Model#', "src/$namespace", $fromFile);

                            $newToDir= dirname($newFromFile);

                            @mkdir($newToDir, 0777, true);

                            rename($fromFile, $newFromFile);

                            $newToDir= dirname(dirname($newFromFile));

                            $fromFile = $newFromFile;
                            $toDir = $newToDir;


                        }
                    }

                    $toFile   = $toDir.'/'.$fileinfo->getFilename();

                    $dirs[$toDir] = $toDir;

                    if ($fileinfo->getExtension() == 'php') {
                        $this->setEndOf($fromFile);
                        $this->createWorkModelClass($fromFile, $toFile);
                    } elseif ($fileinfo->getExtension() == 'bak') {
                        unlink($fromFile);
                    }
                }
            }
        }

        return $dirs;
    }

    /**
     *
     */
    public function postCompileModels()
    {
        if ($this->formatter->getFileExtension() == 'php') {
            $modelDir = $this->workDir;

            foreach (new \DirectoryIterator($modelDir) as $fileinfo) {

                if (!$fileinfo->isDot()) {
                    $modelFile = $modelDir.'/'.$fileinfo->getFilename();

                    if ($fileinfo->getExtension() == 'php') {
                        $this->changeModelClassDef($modelFile);
                    } elseif ($fileinfo->getExtension() == 'bak') {
                        unlink($modelFile);
                    }
                }
            }

            $configDir = dirname(dirname($modelDir)).'/Resources/config';

            $configFromDirXml = $configDir.'/doctrine-xml';
            $configToDirXml   = $configDir.'/doctrine';

            if (is_dir($configFromDirXml)) {
                $this->createDir($configToDirXml);

                foreach (new \DirectoryIterator($configFromDirXml) as $fileinfo) {
                    if (!$fileinfo->isDot()) {
                        $fromXmlFile = $configFromDirXml.'/'.$fileinfo->getFilename();

                        $this->changeXmlMetaModel($fromXmlFile, $configToDirXml);
                    }
                }
            }
            $this->finalCompileModels();
        }
    }

    /**
     * @param \RecursiveDirectoryIterator $dir
     * @param                             $namenamespaceFrom
     * @param                             $namenamespaceTo
     */
    private function changeNamenamespaceModel(\RecursiveDirectoryIterator $dir, $namenamespaceFrom, $namenamespaceTo)
    {
        $namenamespaceFromRgx = preg_quote($namenamespaceFrom, '%');

        foreach ($dir as $fileinfo) {
            if (!in_array($fileinfo->getFilename(), ['.', '..'])) {
                if (!$dir->hasChildren()) {
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

    /**
     *
     */
    private function finalCompileModels()
    {
        $namenamespaceTo = $this->formatter->getRegistry()->config->get(FormatterInterface::CFG_BUNDELE_NAMESPACE_TO);

        $modeDir         = dirname($this->workDir);
        $metaModeDir     = dirname($modeDir).'/Resources/config/doctrine';

        if ($namenamespaceTo) {
            $namenamespaceFrom
                = $this->formatter->getRegistry()->config->get(FormatterInterface::CFG_BUNDELE_NAMESPACE);

            $dir = new \RecursiveDirectoryIterator($modeDir);
            $this->changeNamenamespaceModel($dir, $namenamespaceFrom, $namenamespaceTo);

            $dir = new \RecursiveDirectoryIterator($metaModeDir);
            $this->changeNamenamespaceModel($dir, $namenamespaceFrom, $namenamespaceTo);
        }

        $this->createValidators($metaModeDir);
    }

    /**
     * @param $modelFile
     */
    private function changeModelClassDef($modelFile)
    {
        $fromFileContent = file_get_contents($modelFile);
        $toFileContent   = preg_replace('/(class) ([^\s]+)/', 'abstract \1 \2', $fromFileContent);
        $toFileContent   = preg_replace('/Model\\\/', '', $toFileContent);
        $toFileContent   = preg_replace('/\* @var datetime/', '* @var \DateTime', $toFileContent);
        $toFileContent   = preg_replace('/\* @param datetime/', '* @param \DateTime', $toFileContent);
        $toFileContent   = preg_replace('/\* @return datetime/', '* @return \DateTime', $toFileContent);

        file_put_contents($modelFile, $toFileContent);

        $this->removeORMAnatation($modelFile);
    }

    /**
     * @param $fromXmlFile
     * @param $configToDirXml
     */
    private function changeXmlMetaModel($fromXmlFile, $configToDirXml)
    {
        $fromFileContent = file_get_contents($fromXmlFile);


        if (preg_match('/.*\.Model\.(.*?)$/', pathinfo($fromXmlFile)['filename'], $m)) {
            $toXmlFile = $configToDirXml.'/'.$m[1].'.xml';
            $toFileContent = $fromFileContent;

            preg_match('/name="(.*?)Model(.*?)"/', $fromFileContent, $r);
            $ns = $r[1];

            $toFileContent   = preg_replace('/(repository-class=")(App\\\CoreBundle\\\Entity)(\\\Repository\\\.*?")/', '\1'.$ns.'3', $toFileContent);

            $toFileContent   = preg_replace('/Model\\\/', '', $toFileContent);
            $toFileContent   = preg_replace('/nullable=""/', 'nullable="false"', $toFileContent);
            $toFileContent   = preg_replace('/nullable="1"/', 'nullable="true"', $toFileContent);
            $toFileContent   = preg_replace('/ precision="0" scale="0"/', '', $toFileContent);

            $toFileContent = preg_replace_callback('/(column=|table=)("|\')([^\2]*?)(\2)/', function ($m) {

                if (in_array($m[3], $this->quoteWords)) {
                    $m[3] = '`'.$m[3].'`';
                }

                return $m[1].$m[2].$m[3].$m[4];

            }, $toFileContent);

            $toFileContent = $this->prettyXml(
                $toFileContent, [
                    "/<options\W.*?\/>/i" => function ($m) {
                        $out = $m[0];
                        if (preg_match_all('/(\w+)=(\'|\")(.*?)(\2)/', $m[0], $r)) {
                            $options = [];
                            foreach ($r[1] as $k => $name) {
                                $value = $r[3][$k];
                                $options[] = "<option name=\"{$name}\">{$value}</option>";
                            }
                            $options = implode("\n          ", $options);

                            $out = <<<XML
<options>
          {$options}
      </options>
XML;
                        }

                        return $out;
                    },
                ]
            );

            file_put_contents($toXmlFile, $toFileContent);
        }
    }


    /**
     * @param $fromFile
     * @param $toFile
     */
    private function createWorkModelClass($fromFile, $toFile)
    {
        $fromFileContent = file_get_contents($fromFile);

        $toFileContent = preg_replace('/Model\\\/', '', $fromFileContent);
        $toFileContent = preg_replace('/\\\Model/', '', $toFileContent);
        $toFileContent = preg_replace('/(class) ([^\s]+)/', '\1 \2 extends Model\\\\\2', $toFileContent);

        file_put_contents($toFile, $toFileContent);

        $this->removeUse($toFile);
        $this->removeORMAnatation($toFile);
        $this->removeClassBody($toFile);
        $this->setEndOf($toFile);

        $this->createRepository($toFile, $this->formatter->getRegistry()->config->get(FormatterInterface::CFG_BASE_NAMESPASE));
    }

    /**
     * @param $dirName
     */
    private function createDir($dirName)
    {
        if (!is_dir($dirName)) {
            mkdir($dirName);
        }
    }

    /**
     * @param $configDir
     */
    private function createValidators($configDir)
    {
        $validationsDir = dirname($configDir).'/validation';
        @ mkdir($validationsDir);

        $dir = new \DirectoryIterator($configDir);
        $files = [];
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot()) {
                $fileName = $fileinfo->getFileName();
                $fileName = preg_replace('/\.orm\.xml$/', '.xml', $fileName);
                $files[$fileinfo->getPathName()] = $fileName;
            }
        }
        asort($files);

        $xml
                        = <<<XML
<?xml version="1.0" encoding="UTF-8"?>

<constraint-mapping
        xmlns="http://symfony.com/schema/dic/constraint-mapping"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://symfony.com/schema/dic/constraint-mapping http://symfony.com/schema/dic/services/constraint-mapping-1.0.xsd">
</constraint-mapping>
XML;
        $domTpl = new \DOMDocument();
        $domTpl->loadXML($xml);

        foreach ($files as $modelFilePath => $validationFile) {
            $dom = clone $domTpl;

            $validationFile = $validationsDir.'/'.$validationFile;

            $model = simplexml_load_file($modelFilePath);

            $root   = $dom->documentElement;
            $classE = $dom->createElement('class');

            $className = $model->entity->attributes()['name'].'';
            $classE->setAttribute('name', $className);

            $uniqueConstraints = $model->entity->{'unique-constraints'};
            if (count($uniqueConstraints)) {
                foreach ($uniqueConstraints->{'unique-constraint'} as $uniqueConstraint) {
                    $columns = $uniqueConstraint->attributes()['columns'];
                    $columns = explode(',', $columns);
                    array_walk($columns, function (&$v) {
                        $v = trim($v);
                        $v  = preg_replace('/_id$/', '', $v);
                        $v  = preg_replace('/_/', ' ', $v);
                        $v = ucwords($v);
                        $v = lcfirst($v);
                        $v  = preg_replace('/\s/', '', $v);
                    });

                    $constraintE = $dom->createElement('constraint');
                    $constraintE->setAttribute('name', 'Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity');

                    $optionE = $dom->createElement('option');
                    $optionE->setAttribute('name', 'fields');
                    $constraintE->appendChild($optionE);

                    foreach ($columns as $column) {
                        $valueE = $dom->createElement('value', $column);
                        $optionE->appendChild($valueE);
                    }

                    $objName = 'Object';
                    if (preg_match('/[^\\\]*?$/', $className, $m)) {
                        $objName = $m[0];
                    }

                    $columns = implode(', ', $columns);
                    $optionE = $dom->createElement('option', "$objName (with $columns) already exists.");
                    $optionE->setAttribute('name', 'message');
                    $constraintE->appendChild($optionE);

                    $classE->appendChild($constraintE);

                    $constrains[] = $constraintE;
                }
            }

            $constrains = [];

            $fields = $this->getSortedFields($model->entity->field);

            foreach ($fields as $field) {

                $fieldAttrs = $field->attributes();

                $propertyE = $dom->createElement('property');
                $fieldName = $fieldAttrs['name'].'';
                $propertyE->setAttribute('name', $fieldName);

                if ($fieldAttrs['nullable'] == 'false' && !in_array($fieldAttrs['type'], ['boolean', 'bool'])) {
                    $constraintE = $dom->createElement('constraint');
                    $constraintE->setAttribute('name', 'NotBlank');
                    $propertyE->appendChild($constraintE);

                    $classE->appendChild($propertyE);

                    $constrains[] = $constraintE;
                }

                if (in_array($fieldAttrs['type'], ['dateinterval', 'date', 'datetime', 'datetime_with_millisecond'])) {
                    $constraintE = $dom->createElement('constraint');

                    if ($fieldAttrs['type'] == 'dateinterval') {
                        $constraintE->setAttribute('name', 'DateInterval');
                    } elseif (in_array($fieldAttrs['type'], ['datetime', 'datetime_with_millisecond'])) {
                        $constraintE->setAttribute('name', 'DateTime');
                    } elseif ($fieldAttrs['type'] == 'date') {
                        $constraintE->setAttribute('name', 'Date');
                    }

                    $propertyE->appendChild($constraintE);

                    $classE->appendChild($propertyE);

                    $constrains[] = $constraintE;
                }

                if (in_array($fieldAttrs['type'].'', ['decimal', 'float', 'boolean', 'integer'])) {
                    $type    = $fieldAttrs['type'].'';
                    $typeMap = [
                        'decimal' => 'float',
                        'float'   => 'float',
                        'boolean' => 'bool',
                        'integer' => 'integer',
                    ];

                    $constraintE = $dom->createElement('constraint');
                    $constraintE->setAttribute('name', 'Type');

                    $optionE = $dom->createElement('option', $typeMap[$type]);
                    $optionE->setAttribute('name', 'type');
                    $constraintE->appendChild($optionE);

                    $propertyE->appendChild($constraintE);

                    $classE->appendChild($propertyE);

                    $constrains[] = $constraintE;
                }

                if (is_numeric($fieldAttrs['length'].'')) {
                    if ($fieldAttrs['type'] == 'dateinterval') {
                        continue;
                    }

                    $constraintE = $dom->createElement('constraint');
                    $constraintE->setAttribute('name', 'Length');

                    $optionE = $dom->createElement('option', $fieldAttrs['length'].'');
                    $optionE->setAttribute('name', 'max');

                    $constraintE->appendChild($optionE);

                    $propertyE->appendChild($constraintE);

                    $classE->appendChild($propertyE);

                    $constrains[] = $constraintE;
                }
            }

            $fields = $this->getSortedFields($model->entity->{'one-to-one'});

            foreach ($fields as $field) {
                $fieldAttrs = $field->attributes();

                $propertyE = $dom->createElement('property');
                $fieldType = '\\'.$fieldAttrs['target-entity'].'';
                $fieldType = preg_replace('/^\\\/', '\\', $fieldType);
                $fieldName = $fieldAttrs['field'].'';

                $propertyE->setAttribute('name', $fieldName);

                $joinFieldAttrs = $field->{'join-columns'}->{'join-column'}->attributes();

                if ($joinFieldAttrs['nullable'] == 'false') {
                    $constraintE = $dom->createElement('constraint');
                    $constraintE->setAttribute('name', 'NotBlank');
                    $propertyE->appendChild($constraintE);
                }

                $constraintE = $dom->createElement('constraint');
                $constraintE->setAttribute('name', 'Type');

                $optionE = $dom->createElement('option', $fieldType);
                $optionE->setAttribute('name', 'type');
                $constraintE->appendChild($optionE);

                $propertyE->appendChild($constraintE);

                $classE->appendChild($propertyE);

                $classE->appendChild($propertyE);
            }

            $fields = $this->getSortedFields($model->entity->{'many-to-one'});

            foreach ($fields as $field) {
                $fieldAttrs = $field->attributes();

                $propertyE = $dom->createElement('property');
                $fieldType = '\\'.$fieldAttrs['target-entity'].'';
                $fieldType = preg_replace('/^\\\/', '\\', $fieldType);
                $fieldName = $fieldAttrs['field'].'';

                $propertyE->setAttribute('name', $fieldName);

                $joinFieldAttrs = $field->{'join-columns'}->{'join-column'}->attributes();

                if ($joinFieldAttrs['nullable'] == 'false') {
                    $constraintE = $dom->createElement('constraint');
                    $constraintE->setAttribute('name', 'NotBlank');
                    $propertyE->appendChild($constraintE);
                }

                $constraintE = $dom->createElement('constraint');
                $constraintE->setAttribute('name', 'Type');

                $optionE = $dom->createElement('option', $fieldType);
                $optionE->setAttribute('name', 'type');
                $constraintE->appendChild($optionE);

                $propertyE->appendChild($constraintE);

                $classE->appendChild($propertyE);

                $constrains[] = $constraintE;
            }

            if (count($constrains)) {
                $root->appendChild($classE);
            }

            $xml = $this->prettyXml($dom->saveXML());

            file_put_contents($validationFile, $xml);
        }
    }

    private function getSortedFields($fieldsIn)
    {
        $fields = [];
        foreach ($fieldsIn as $field) {
            $fields[] = $field;
        }

        usort($fields, function ($a, $b) {
            return strcmp($a->attributes()['name'], $b->attributes()['name']);
        });

        return $fields;
    }
    /**
     * @param $file
     */
    private function removeORMAnatation($file)
    {
        $toFileContent = file($file);
        foreach ($toFileContent as $k => $line) {
            if (preg_match('/\s+\*\s+@ORM/', $line, $m) ||
                preg_match('/\s+as\s+ORM/', $line, $m)) {
                unset($toFileContent[$k]);
            }
        }

        $toFileContent = implode('', $toFileContent);
        $toFileContent = preg_replace("/\/\*\*\n \*\n \*\//", '', $toFileContent);
        $toFileContent = preg_replace("/(\n+)(\nabstract class)/", "\n".'\2', $toFileContent);
        $toFileContent = preg_replace("/(\n+)(\nclass)/", "\n".'\2', $toFileContent);

        file_put_contents($file, $toFileContent);
    }

    /**
     * @param        $eFile
     * @param string $baseNamespace
     */
    private function createRepository($eFile, $baseNamespace)
    {
        $pathinfo  = pathinfo($eFile);
        $className = $pathinfo['filename'].'Repository';
        $repoDir   = $pathinfo['dirname'].'/Repository';
        $repoFile  = $repoDir.'/'.$className.'.php';

        $toFileContent = file($eFile);

        $namespace = null;
        foreach ($toFileContent as $line) {
            if (preg_match('/^namespace\s/', $line, $m)) {
                $namespace = preg_replace('/namespace\s(.*);/', '\1', $line);
                $namespace = trim($namespace);
                break;
            }
        }

        $repositoryClass
            = <<<PHP
<?php

namespace ${namespace}\Repository;

use Common\DoctrineBundle\ORM\EntityRepository;

class ${className} extends EntityRepository
{
}

PHP;

        $this->createDir($repoDir);

        file_put_contents($repoFile, $repositoryClass);
    }

    /**
     * @param $file
     */
    private function removeClassBody($file)
    {
        $toFileContent    = [];
        $toFileContentTmp = file($file);

        foreach ($toFileContentTmp as $k => $line) {
            $toFileContent[] = $line;
            if (preg_match('/^class\s/', $line, $m)) {
                $toFileContent[] = "{\n";
                $toFileContent[] = '}';
                break;
            }
        }
        $toFileContent = implode('', $toFileContent);
        file_put_contents($file, $toFileContent);
    }

    /**
     * @param $file
     */
    private function removeUse($file)
    {
        $toFileContent = file($file);

        foreach ($toFileContent as $k => $line) {
            if (preg_match('/^use\s/', $line, $m)) {
                unset($toFileContent[$k]);
            }
        }
        $toFileContent = implode('', $toFileContent);
        file_put_contents($file, $toFileContent);
    }

    /**
     * @param $file
     */
    private function setEndOf($file)
    {
        $toFileContent = file_get_contents($file);
        $toFileContent = trim($toFileContent)."\n";

        file_put_contents($file, $toFileContent);
    }
}
