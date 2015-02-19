<?php

/*
 * The MIT License
 *
 * Copyright (c) 2010 Johannes Mueller <circus2(at)web.de>
 * Copyright (c) 2012-2013 Toha <tohenk@yahoo.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace MwbExporter\Formatter\Doctrine2\Annotation\Model;

use MwbExporter\Formatter\Doctrine2\Model\Table as BaseTable;
use Doctrine\Common\Inflector\Inflector;
use MwbExporter\Object\Annotation;
use MwbExporter\Writer\WriterInterface;
use MwbExporter\Formatter\Doctrine2\Annotation\Formatter;

class Table extends BaseTable
{
    protected $ormPrefix = null;
    protected $collectionClass = 'Doctrine\Common\Collections\ArrayCollection';
    protected $collectionInterface = 'Doctrine\Common\Collections\Collection';

    /**
     * Get the array collection class name.
     *
     * @param bool $useFQCN return full qualified class name
     * @return string
     */
    public function getCollectionClass($useFQCN = true)
    {
        $class = $this->collectionClass;
        if (!$useFQCN && count($array = explode('\\', $class))) {
            $class = array_pop($array);
        }

        return $class;
    }

    /**
     * Get collection interface class name.
     *
     * @param bool $absolute Use absolute class name
     * @return string
     */
    public function getCollectionInterface($absolute = true)
    {
        return ($absolute ? '\\' : '').$this->collectionInterface;
    }

    /**
     * Get annotation prefix.
     *
     * @param string $annotation Annotation type
     * @return string
     */
    public function addPrefix($annotation = null)
    {
        if (null === $this->ormPrefix) {
            $this->ormPrefix = '@'.$this->getDocument()->getConfig()->get(Formatter::CFG_ANNOTATION_PREFIX);
        }

        return $this->ormPrefix.($annotation ? $annotation : '');
    }

    /**
     * Quote identifier if necessary. Quoting is enabled if configuration `CFG_QUOTE_IDENTIFIER` is set
     * to true.
     *
     * @param string $value  The identifier to quote
     * @return string
     */
    public function quoteIdentifier($value)
    {
        return $this->getDocument()->getConfig()->get(Formatter::CFG_QUOTE_IDENTIFIER) ? '`'.$value.'`' : $value;
    }

    /**
     * Get annotation object.
     *
     * @param string $annotation  The annotation name
     * @param mixed  $content     The annotation content
     * @param array  $options     The annotation options
     * @return \MwbExporter\Object\Annotation
     */
    public function getAnnotation($annotation, $content = null, $options = array())
    {
        return new Annotation($this->addPrefix($annotation), $content, $options);
    }

    /**
     * Get indexes annotation.
     *
     * @return array|null
     */
    protected function getIndexesAnnotation()
    {
        $indices = array();
        foreach ($this->indexes as $index) {
            if($index->isIndex()){
                $indices[] = $this->getAnnotation('Index', $index->asAnnotation());
            }
        }

        return count($indices) ? $indices : null;
    }

    /**
     * Get unique constraints annotation.
     *
     * @return array|null
     */
    protected function getUniqueConstraintsAnnotation()
    {
        $uniques = array();
        foreach ($this->indexes as $index) {
            if($index->isUnique()){
                $uniques[] = $this->getAnnotation('UniqueConstraint', $index->asAnnotation());
            }
        }

        return count($uniques) ? $uniques : null;
    }

    /**
     * Get join annotation.
     *
     * @param string $joinType    Join type
     * @param string $entity      Entity name
     * @param string $mappedBy    Column mapping
     * @param string $inversedBy  Reverse column mapping
     * @return \MwbExporter\Object\Annotation
     */
    public function getJoinAnnotation($joinType, $entity, $mappedBy = null, $inversedBy = null)
    {
        return $this->getAnnotation($joinType, array('targetEntity' => $entity, 'mappedBy' => $mappedBy, 'inversedBy' => $inversedBy));
    }

    /**
     * Get column join annotation.
     *
     * @param string $local       Local column name
     * @param string $foreign     Reference column name
     * @param string $deleteRule  On delete rule
     * @return \MwbExporter\Object\Annotation
     */
    public function getJoinColumnAnnotation($local, $foreign, $deleteRule = null)
    {
        return $this->getAnnotation('JoinColumn', array('name' => $local, 'referencedColumnName' => $foreign,
                                                       'onDelete' => $this->getDocument()->getFormatter()->getDeleteRule($deleteRule)));
    }

    public function writeTable(WriterInterface $writer)
    {
        if (!$this->isExternal()) {

            if(strpos( $this->quoteIdentifier($this->getRawTableName()), '_2_') !== false ) {
                return self::WRITE_M2M;
            }


            $namespace = $this->getEntityNamespace();
            if ($repositoryNamespace = $this->getDocument()->getConfig()->get(Formatter::CFG_REPOSITORY_NAMESPACE)) {
                $base = $this->getDocument()->getConfig()->get(Formatter::CFG_BUNDELE_NAMESPACE_TO);
                $repositoryNamespace = $base. '\\'.  $repositoryNamespace. '\\';
            }
            $skipGetterAndSetter = $this->getDocument()->getConfig()->get(Formatter::CFG_SKIP_GETTER_SETTER);
            $serializableEntity  = $this->getDocument()->getConfig()->get(Formatter::CFG_GENERATE_ENTITY_SERIALIZATION);
            $toArrrabeEntity  = $this->getDocument()->getConfig()->get(Formatter::CFG_GENERATE_ENTITY_TO_ARRAY);

            $lifecycleCallbacks  = $this->getLifecycleCallbacks();

            $comment = $this->getComment();
            $writer
                ->open($this->getTableFileName())
                ->write('<?php')
                ->write('')
                ->write('namespace %s;', $namespace)
                ->writeCallback(function(WriterInterface $writer, Table $_this = null) {
                    $_this->writeUsedClasses($writer);
                })
                ->write('/**')
                //->write(' * '.$this->getNamespace(null, false))
                ->write(' *')
                ->writeIf($comment, $comment)
                ->write(' * '.$this->getAnnotation('Table', array('name' => $this->quoteIdentifier($this->getRawTableName()), 'indexes' => $this->getIndexesAnnotation(), 'uniqueConstraints' => $this->getUniqueConstraintsAnnotation())))
                ->write(' * '.$this->getAnnotation('Entity', array('repositoryClass' => $this->getDocument()->getConfig()->get(Formatter::CFG_AUTOMATIC_REPOSITORY) ? $repositoryNamespace.$this->getModelName().'Repository' : null)))
                ->writeIf($lifecycleCallbacks, ' * @ORM\HasLifecycleCallbacks')
                ->write(' */')
                ->write('class '.$this->getModelName().(($implements = $this->getClassImplementations()) ? ' implements '.$implements : ''))
                ->write('{')
                ->indent()
                    ->writeCallback(function(WriterInterface $writer, Table $_this = null) use ($skipGetterAndSetter, $serializableEntity, $toArrrabeEntity, $lifecycleCallbacks) {
                        $_this->writePreClassHandler($writer);
                        $_this->getColumns()->write($writer);
                        $_this->writeManyToMany($writer);
                        $_this->writeConstructor($writer);
                        if (!$skipGetterAndSetter) {
                            $_this->getColumns()->writeGetterAndSetter($writer);
                            $_this->writeManyToManyGetterAndSetter($writer);
                        }
                        $_this->writePostClassHandler($writer);
                        foreach ($lifecycleCallbacks as $callback => $handlers) {
                            foreach ($handlers as $handler) {
                                $writer
                                    ->write('/**')
                                    ->write(' * @ORM\%s', ucfirst($callback))
                                    ->write(' * @throws \Symfony\Component\Intl\Exception\MethodNotImplementedException')
                                    ->write(' */')
                                    ->write('public function %s()', $handler)
                                    ->write('{')
                                    ->indent()
                                    ->write('throw new \Symfony\Component\Intl\Exception\MethodNotImplementedException(__METHOD__);')
                                    ->outdent()
                                    ->write('}')
                                    ->write('')
                                ;
                            }

                        }
                        if ($serializableEntity) {
                            $_this->writeSerialization($writer);
                        }

                        if ($toArrrabeEntity) {
                            $_this->writeToArray($writer);
                        }

                        $_this->writeToString($writer);
                        if($this->getColumns()->columnExits('id')) {
                            $writer->write('');
                            $_this->writeIsNew($writer);
                        }
                    })
                ->outdent()
                ->write('}')
                ->close()
            ;

            return self::WRITE_OK;
        }

        return self::WRITE_EXTERNAL;
    }

    public function writeIsNew(WriterInterface $writer)
    {
        $column = $this->getColumns()->getColumnByName('id');
        $name = $column->getPhpColumnName();
        $writer
            ->write('/**')
            ->write(' * check is new object')
            ->write(' * @return boolean')
            ->write(' */')
            ->write('public function isNew()')
            ->write('{')
                ->indent()
                ->write("return !(boolean)\$this->{$name};")
                ->outdent()
            ->write('}')
        ;

        return $this;
    }

    public function writeUsedClasses(WriterInterface $writer)
    {
        $uses = $this->getUsedClasses();
        if (count($uses)) {

            $writer->write('');
            foreach ($uses as $use) {
                $writer->write('use %s;', $use);
            }
            $writer->write('');
        }

        return $this;
    }

    public function writeConstructor(WriterInterface $writer)
    {
        $writer
            ->write('/**')
            ->write(' * only construct object')
            ->write(' */')
            ->write('public function __construct()')
            ->write('{')
            ->indent()
                ->writeCallback(function(WriterInterface $writer, Table $_this = null) {

                    $maxLen=0;

                    $fields = [];
                    foreach ($_this->getManyToManyRelations() as $relation) {
                        $field = lcfirst(Inflector::pluralize($relation['refTable']->getModelName()));
                        $maxLen = max($maxLen, strlen($field));
                        $fields[]  = $field;
                    }

                    $_this->getColumns()->writeArrayCollections($writer, $maxLen);

                    foreach ($fields as $field) {
                       $format = "\$this->%-{$maxLen}s = new %s();";
                       $writer->write($format, $field, $_this->getCollectionClass(false));
                    }
                })
            ->outdent()
            ->write('}')
            ->write('')
        ;

        return $this;
    }

    public function writeToString(WriterInterface $writer)
    {
        $throwException = false;

        if($this->getColumns()->columnExits('name')) {

            $column = $this->getColumns()->getColumnByName('name');

        } else if($this->getColumns()->columnExits('id')) {

            $column = $this->getColumns()->getColumnByName('id');

        } else {
            $throwException = true;
            $column         = null;
        }


        //* @throws MethodNotImplementedException
        $writer
            ->write('/**')
            ->write(' * to string entity')
            ->write(' * @return string');

        if($throwException) {
            $writer->write(' * @throws \Symfony\Component\Intl\Exception\MethodNotImplementedException');
        }

        $writer->write(' */')
            ->write('public function __toString()')
            ->write('{');

        if(!isset($column) && $throwException) {

            $writer->indent()
                ->write('throw new \Symfony\Component\Intl\Exception\MethodNotImplementedException(__METHOD__);')
                ->write("return '';")
                ->outdent();

        } else {

            $name = $column->getPhpColumnName();
            $writer->indent()
                ->write("return (string)\$this->{$name};")
                ->outdent();
        }

        $writer->write('}')
        ;


        return $this;
    }

    public function writeSerialization(WriterInterface $writer)
    {
        $columns = $this->getColumns()->getColumns();
        $writer
            ->write('/**')
            ->write(' * get data for serialize object')
            ->write(' * @return array')
            ->write(' */')
            ->write('public function __sleep()')
            ->write('{')
            ->indent()
                ->write('return [%s];', implode(', ', array_map(function($column) {
                    return sprintf('\'%s\'', $column->getPhpColumnName());
                }, $columns)))
            ->outdent()
            ->write('}')
            ->write('')
        ;

        return $this;
    }

    public function writeToArray(WriterInterface $writer)
    {
        $columns = $this->getColumns()->getColumns();

        $columns = array_filter($columns, function($column) {
                return !preg_match('/_id$/', $column->getColumnName());
            });

        $maxLen = 0;
        foreach($columns as $column) {
            $maxLen = max($maxLen, strlen($column->getPhpColumnName()));
        }

        $maxLen    += 2;
        $columnsArr = [];

        foreach ($columns as $column) {
            $columnKey = $column->getPhpColumnName();

            if(in_array($column->asAnnotation()['type'], ['datetime'])) {

                $format       = "    %-{$maxLen}s => \$this->%s ? \$this->%s->format('Y-m-d H:i:s') : \$this->%s";
                $columnsArr[] = sprintf($format, '\'' . $columnKey . '\'', $columnKey, $columnKey, $columnKey);

            } else if(in_array($column->asAnnotation()['type'], ['datetime_with_millisecond'])) {

                $format = "    %-{$maxLen}s => \$this->%s ? \$this->%s->format('Y-m-d H:i:s.u') : \$this->%s";
                $columnsArr[] = sprintf($format, '\''.$columnKey.'\'', $columnKey, $columnKey, $columnKey);

            } else if(in_array($column->asAnnotation()['type'], ['date'])) {

                $format = "    %-{$maxLen}s => \$this->%s ? \$this->%s->format('Y-m-d') : \$this->%s";
                $columnsArr[] = sprintf($format, '\''.$columnKey.'\'', $columnKey, $columnKey, $columnKey);

            } else if(in_array($column->asAnnotation()['type'], ['dateinterval'])) {

                $format = "    %-{$maxLen}s => \$this->%s ? \$this->%s->format(null) : \$this->%s";
                $columnsArr[] = sprintf($format, '\''.$columnKey.'\'', $columnKey, $columnKey, $columnKey);

            } else {

                $format = "    %-{$maxLen}s => \$this->%s";
                $columnsArr[] = sprintf($format, '\''.$columnKey.'\'', $columnKey);
            }
        }

        $writer
            ->write('/**')
            ->write(' * get data as array')
            ->write(' * @return array')
            ->write(' */')
            ->write('public function toArray()')
            ->write('{')
            ->indent()
                ->write("return [")
                ->write(join(",\n", $columnsArr))
                ->write("];")
            ->outdent()
            ->write('}')
            ->write('')
        
            //->write('')
            //->write('/**')
            //->write(' * @param array $data')
            //->write(' * @return $this')
            //->write(' * @throws \InvalidArgumentException')
            //->write(' */')
            //->write('public function fromArray(array $data = [])')
            //->write('{')
            //->indent()
            //    ->write('$map = $this->toArray();')
            //    ->write('foreach($data as $key => $value) {')
            //    ->indent()
            //        ->write('if(array_key_exists($key, $map)) {')
            //        ->indent()
            //            ->write('$this->$key = $value;')
            //        ->outdent()
            //        ->write('} else {')
            //        ->indent()
            //            ->write('throw new \InvalidArgumentException(sprintf(\'The class "%s" has not property "%s".\', __CLASS__, $key));')
            //        ->outdent()
            //        ->write('}')
            //    ->outdent()
            //    ->write('}')
            //->outdent()
            //->write('}')
            //->write('')
        ;

        return $this;
    }


    public function writeManyToMany(WriterInterface $writer)
    {
        $mappedRelation = null;
        $formatter = $this->getDocument()->getFormatter();
        foreach ($this->manyToManyRelations as $relation) {
            $isOwningSide = $formatter->isOwningSide($relation, $mappedRelation);

            $annotationOptions = array(
                'targetEntity' => $relation['refTable']->getModelNameAsFQCN($this->getEntityNamespace()),
                'mappedBy' => null,
                'inversedBy' => lcfirst(Inflector::pluralize($this->getModelName())),
                'cascade' => $formatter->getCascadeOption($relation['reference']->parseComment('cascade')),
                'fetch' => $formatter->getFetchOption($relation['reference']->parseComment('fetch')),
            );

            // if this is the owning side, also output the JoinTable Annotation
            // otherwise use "mappedBy" feature
            if ($isOwningSide) {
                if ($mappedRelation->parseComment('unidirectional') === 'true') {
                    unset($annotationOptions['inversedBy']);
                }

                $nativeType = $this->getCollectionClass(false);
                $targetEntity = $annotationOptions['targetEntity'];

                $writer
                    ->write('/**')
                    ->write(' * collection of '.$targetEntity)
                    ->write(' * @var '.$nativeType.'|\\'.$relation['refTable']->getModelNameAsFQCN().'[]')
                    ->write(' * '.$this->getAnnotation('ManyToMany', $annotationOptions))
                    ->write(' * '.$this->getAnnotation('JoinTable',
                        array(
                            'name'               => $relation['reference']->getOwningTable()->getRawTableName(),
                            'joinColumns'        => array(
                                $this->getJoinColumnAnnotation(
                                    $relation['reference']->getForeign()->getColumnName(),
                                    $relation['reference']->getLocal()->getColumnName(),
                                    $relation['reference']->getParameters()->get('deleteRule')
                                )
                            ),
                            'inverseJoinColumns' => array(
                                $this->getJoinColumnAnnotation(
                                    $mappedRelation->getForeign()->getColumnName(),
                                    $mappedRelation->getLocal()->getColumnName(),
                                    $mappedRelation->getParameters()->get('deleteRule')
                                )
                            )
                        ), array('multiline' => true, 'wrapper' => ' * %s')))
                    ->write(' */')
                ;
            } else {
                if ($relation['reference']->parseComment('unidirectional') === 'true') {
                    continue;
                }

                $nativeType = $this->getCollectionClass(false);
                $targetEntity = $annotationOptions['targetEntity'];

                $annotationOptions['mappedBy'] = $annotationOptions['inversedBy'];
                $annotationOptions['inversedBy'] = null;

                $writer
                    ->write('/**')
                    ->write(' * collection of '.$targetEntity)
                    ->write(' * @var '.$nativeType.'|\\'.$relation['refTable']->getModelNameAsFQCN().'[]')
                    ->write(' * '.$this->getAnnotation('ManyToMany', $annotationOptions))
                    ->write(' */')
                ;
            }
            $writer
                ->write('protected $'.lcfirst(Inflector::pluralize($relation['refTable']->getModelName())).';')
                ->write('')
            ;
        }

        return $this;
    }

    public function writeManyToManyGetterAndSetter(WriterInterface $writer)
    {
        $mappedRelation = null;
        $formatter = $this->getDocument()->getFormatter();
        foreach ($this->manyToManyRelations as $relation) {
            $isOwningSide = $formatter->isOwningSide($relation, $mappedRelation);

            $typeEntity = $relation['refTable']->getNamespace();

            // add
            $writer
                ->write('/**')
                ->write(' * Add '.$relation['refTable']->getModelName().' entity to collection (many to many).')
                ->write(' *')
                ->write(' * @param '. $typeEntity.' $'.lcfirst($relation['refTable']->getModelName()))
                ->write(' * @return '.$this->getNamespace($this->getModelName()))
                ->write(' */')
                ->write('public function add'.$relation['refTable']->getModelName().'('.$typeEntity.' $'.lcfirst($relation['refTable']->getModelName()).')')
                ->write('{')
                ->indent()
                    ->writeCallback(function(WriterInterface $writer, Table $_this = null) use ($isOwningSide, $relation, $mappedRelation) {
                        if ($isOwningSide) {
                            $writer->write('$%s->add%s($this);', lcfirst($relation['refTable']->getModelName()), $_this->getModelName());
                        }
                    })
                    ->write('$this->'.lcfirst(Inflector::pluralize($relation['refTable']->getModelName())).'[] = $'.lcfirst($relation['refTable']->getModelName()).';')
                    ->write('')
                    ->write('return $this;')
                ->outdent()
                ->write('}')
                ->write('')
            ;

            // remove
            $writer
                ->write('/**')
                ->write(' * Remove '.$relation['refTable']->getModelName().' entity to collection (many to many).')
                ->write(' *')
                ->write(' * @param '. $typeEntity.' $'.lcfirst($relation['refTable']->getModelName()))
                ->write(' * @return '.$this->getNamespace($this->getModelName()))
                ->write(' */')
                ->write('public function remove'.$relation['refTable']->getModelName().'('.$typeEntity.' $'.lcfirst($relation['refTable']->getModelName()).')')
                ->write('{')
                ->indent()
                ->writeCallback(function(WriterInterface $writer, Table $_this = null) use ($isOwningSide, $relation, $mappedRelation) {
                        if ($isOwningSide) {
                            $writer->write('$%s->remove%s($this);', lcfirst($relation['refTable']->getModelName()), $_this->getModelName());
                        }
                    })
                ->write('$this->'.lcfirst(Inflector::pluralize($relation['refTable']->getModelName())).'->removeElement($'.lcfirst($relation['refTable']->getModelName()).');')
                ->write('')
                ->write('return $this;')
                ->outdent()
                ->write('}')
                ->write('')
            ;

            // get
            $writer->write('/**')
                ->write(' * Get '.$relation['refTable']->getModelName().' entity collection (many to many).')
                ->write(' *')
                ->write(' * @return '.$this->getCollectionInterface().'|'.$relation['refTable']->getNamespace().'[]')
                ->write(' */')
                ->write('public function get'.Inflector::pluralize($relation['refTable']->getModelName()).'()')
                ->write('{')
                ->indent()
                    ->write('return $this->'.lcfirst(Inflector::pluralize($relation['refTable']->getModelName())).';')
                ->outdent()
                ->write('}')
                ->write('')
            ;
        }

        return $this;
    }

    /**
     * Get the class name to implements.
     *
     * @return string
     */
    protected function getClassImplementations()
    {
    }

    /**
     * Get used classes.
     *
     * @return array
     */
    public  function getUsedClasses()
    {
        $uses = array();
        if ('@ORM\\' === $this->addPrefix()) {
            $uses[] = 'Doctrine\ORM\Mapping as ORM';
        }
        if (count($this->getManyToManyRelations()) || $this->getColumns()->hasOneToManyRelation()) {
            $uses[] = $this->getCollectionClass();
        }

        return $uses;
    }

    /**
     * Write pre class handler.
     *
     * @param \MwbExporter\Writer\WriterInterface $writer
     * @return \MwbExporter\Formatter\Doctrine2\Annotation\Model\Table
     */
    public function writePreClassHandler(WriterInterface $writer)
    {
        return $this;
    }

    /**
     * Write post class handler.
     *
     * @param \MwbExporter\Writer\WriterInterface $writer
     * @return \MwbExporter\Formatter\Doctrine2\Annotation\Model\Table
     */
    public function writePostClassHandler(WriterInterface $writer)
    {
        return $this;
    }
}
