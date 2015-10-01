<?php

namespace MwbExporter;

require 'XmlPrettyTrait.php';

class Patcher
{
    use XmlPrettyTrait;

    /**
     * root dir
     * @var string
     */
    private $baseDir;

    //'Account'                     => '*',
    private $classesVersionedElements= [];

    /**
     * @param array $classesVersionedElements
     */
    public function setClassesVersionedElements($classesVersionedElements)
    {
        $this->classesVersionedElements = $classesVersionedElements;
    }

    /**
     * @param $baseDir
     */
    function __construct($baseDir)
    {
        $this->baseDir = $baseDir;
    }

    /**
     *
     */
    public function doPatch()
    {
        try {

            foreach (new \DirectoryIterator($this->baseDir . '/Resources/config/doctrine') as $fileInfo) {
                $this->patchXml($fileInfo);
            }

            foreach (new \DirectoryIterator($this->baseDir . '/Entity/Repository') as $fileInfo) {
                $this->patchRepository($fileInfo);
            }

            foreach (new \DirectoryIterator($this->baseDir . '/Entity') as $fileInfo) {
                $this->patchEntity($fileInfo);
            }

            foreach (new \DirectoryIterator($this->baseDir . '/Entity/Model') as $fileInfo) {
                $this->patchEntityModel($fileInfo);
            }

            foreach (new \DirectoryIterator($this->baseDir . '/Resources/config/validation') as $fileInfo) {
                $this->patchValidatorXml($fileInfo);
            }

        } catch (\Exception $e) {
            echo $e;
        }

    }

    /**
     * @param DirectoryIterator $fileInfo
     */
    private function patchValidatorXml($fileInfo)
    {
        if ($fileInfo->isFile()) {

            $filePath = $fileInfo->getRealPath();
            $content = file_get_contents($filePath);

            $contentMap = [
                '"DateInterval"' => '"\Common\CoreBundle\Validator\Constraints\DateInterval"',
            ];

            $content = str_replace(array_keys($contentMap), array_values($contentMap), $content);

            file_put_contents($filePath, $content);
        }
    }

    /**
     * @param DirectoryIterator $fileInfo
     */
    private function patchXml(\DirectoryIterator $fileInfo)
    {
        if ($fileInfo->isFile()) {

            $filePath = $fileInfo->getPathname();
            if(!preg_match('/\.orm\.xml$/', $fileInfo->getFilename())) {
                return;
            } else {
                preg_match('/(.*)\.orm\.xml/', $fileInfo->getFilename(), $m);
                $className = $m[1];
            }

            //replase
            $contentMap = [
                'column="order"' => 'column="`order`"',
                'column="from"'  => 'column="`from`"',
                'column="to"'    => 'column="`to`"',
                'column="user"'  => 'column="`user`"',
            ];

            $content = file_get_contents($filePath);
            $content = str_replace(array_keys($contentMap), array_values($contentMap), $content);

            //rename
            $filenameMap = [
            ];

            if (isset($filenameMap[$fileInfo->getFilename()])) {
                unlink($filePath);
                $filePath = $fileInfo->getPath() . '/' . $filenameMap[$fileInfo->getFilename()];
            }

            $content = $this->addGedmoLoggable($content, $className);
            file_put_contents($filePath, $content);
        }
    }


    public function addGedmoLoggable($xml, $className)
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        if(isset($this->classesVersionedElements[$className])) {

            $classVersionedElements = $this->classesVersionedElements[$className];

            $root = $dom->getElementsByTagName('doctrine-mapping')->item(0);
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:gedmo', 'http://gediminasm.org/schemas/orm/doctrine-extensions-mapping');

            $loggableE = $dom->createElement('gedmo:loggable');
            $loggableE->setAttribute('log-entry-class', 'App\CoreBundle\Document\BusEvent');

            $entityE = $dom->getElementsByTagName('entity')->item(0);
            $entityE->appendChild($loggableE);

            $idE = $dom->getElementsByTagName('id')->item(0);
            $entityE->insertBefore($loggableE, $idE);

            foreach($dom->getElementsByTagName('*') as $e) {

                /** @var \DOMElement $e */

                if(in_array($e->tagName, ['field', 'many-to-one', 'one-to-one'])) {

                    if($e->getAttribute('mapped-by')) {
                        continue;
                    }

                    if($classVersionedElements === '*' ||
                       (is_array($classVersionedElements) && array_intersect([$e->getAttribute('name'), $e->getAttribute('field')], $classVersionedElements))) {

                        $versionedE = $dom->createElement('gedmo:versioned');
                        $e->appendChild($versionedE);
                    }
                }
            }
        }

        $xml = $this->prettyXml($dom->saveXML(), [
            '/( xmlns:gedmo=)/'             => "\n       \\1",
            '/(\s+<gedmo:loggable.*?\/>)/'  => "\n\\1",
            '/>(<gedmo:versioned\/>)/'      => ">\n      \\1\n    ",
            "/\n    (<gedmo:versioned\/>)/" => "\n      \\1\n    ",
        ]);

        return $xml;
    }

    /**
     * Entity/Repository
     * @param DirectoryIterator $fileInfo
     */
    private function patchRepository(\DirectoryIterator $fileInfo)
    {
        if ($fileInfo->isFile()) {

            $filePath = $fileInfo->getPathname();

            $contentMap = [
            ];

            $content = file_get_contents($filePath);
            $content = str_replace(array_keys($contentMap), array_values($contentMap), $content);

            file_put_contents($filePath, $content);
        }

    }

    /**
     * Entity
     * @param DirectoryIterator $fileInfo
     */
    private function patchEntity(\DirectoryIterator $fileInfo)
    {
        if ($fileInfo->isFile()) {

            $filePath = $fileInfo->getPathname();
            $content = file_get_contents($filePath);

            $contentMap = [

                '/(class .*? extends .*?)(\n\{)/'=>'\1\2'.<<<PHP

    /**
     * get data as array
     *
     * @return array
     */
    public function toArray()
    {
        \$res = parent::toArray();

        return [\$this->getShortClassName()=>\$res];
    }
PHP
                ,
            ];

            $form    = array_keys($contentMap);
            $to      = array_values($contentMap);
            $content = preg_replace($form, $to, $content);

            file_put_contents($filePath, $content);
        }
    }

    /**
     * Entity/Model
     * @param DirectoryIterator $fileInfo
     */
    private function patchEntityModel(\DirectoryIterator $fileInfo)
    {
        if ($fileInfo->isFile()) {

            $filePath = $fileInfo->getPathname();
            $content = file_get_contents($filePath);

            $contentMap = [
                '/\\\DateInterval/'                                              => 'Type\DateInterval',
                '/\?\s+?(\$this->.*?)->format\(\'Y-m-d\'\)\s+?:/'                => '? \1->format(Type\Date::DEFAULT_FORMAT) :',
                '/\?\s+?(\$this->.*?)->format\(\'Y-m-d H:i:s\'\)\s+?:/'          => '? \1->format(Type\DateTime::DEFAULT_FORMAT) :',
                '/\?\s+?(\$this->.*?)->format\(\'Y-m-d H:i:s\.u\'\)\s+?:/'       => '? Type\DateTime::formatWithMillisecond(\1) :',
                '/\?\s+?(\$this->.*?)->format\(\'P%yY%mM%dDT%hH%iI%sS\'\)\s+?:/' => '? \1->format(null) :',
                '/(\s+)(\*)(\s+)\n/'                                             => '\1\2'."\n",
                '/(abstract class .*?)(\n\{)/'=>'\1\2'.<<<PHP

    use Type\\ModelTrait;

PHP
            ];

            $from    = array_keys($contentMap);
            $to      = array_values($contentMap);
            $content = preg_replace($from, $to, $content);

            $imeplements = ['\Common\CoreBundle\Type\EntityInterface'];
            if(preg_match('#(abstract class [^\\\]*?\s+?implements\s+?)(.*)#', $content, $m)) {

                $m = explode(',', $m[2]);
                array_walk($m, 'trim');
                $imeplements = array_merge($imeplements, $m);

            }

            if(preg_match('/toArray/', $content)) {
                $imeplements[] = '\Common\CoreBundle\Type\ArraybleInterface';
            }

            $imeplements = array_values($imeplements);
            $imeplements = implode(', ', $imeplements);
            $content = preg_replace("#(abstract class [^\\\]*?)(\s+?implements\s+?)(.*)(\n\{)#", '\1\4', $content);
            $content = preg_replace("/(abstract class .*?)(\n\{)/", '\1 implements '.$imeplements.'\2', $content);

            if(preg_match('/Type\\\/', $content)) {
                $content = preg_replace('#\\\Common\\\CoreBundle\\\Type#', 'Type', $content);
                $content = preg_replace('/namespace (.*?);/', 'namespace \1;'."\n\n".'use Common\\CoreBundle\\Type;', $content);

            }

            $content = preg_replace("/(use .*?;)\n{2}(use .*?;)/", '\1'."\n".'\2', $content);

            file_put_contents($filePath, $content);
        }
    }
}