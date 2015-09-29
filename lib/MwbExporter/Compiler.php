<?php

namespace MwbExporter;

use MwbExporter\Formatter\FormatterInterface;
use MwbExporter\Model\Document;
use Symfony\Component\Validator\Validation;

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
        'group',
        'user',
        'from',
        'to',
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

        $createtor = new ValidationCreatetor($metaModeDir);
        $createtor->create();
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
