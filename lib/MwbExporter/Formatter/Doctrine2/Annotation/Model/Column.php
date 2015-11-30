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

use MwbExporter\Formatter\Doctrine2\Model\Column as BaseColumn;
use Doctrine\Common\Inflector\Inflector;
use MwbExporter\Writer\WriterInterface;

class Column extends BaseColumn
{
    public function asAnnotation()
    {
        $attributes = [
            'name' => $this->getTable()->quoteIdentifier($this->getColumnName()),
            'type' => $this->getDocument()->getFormatter()->getDatatypeConverter()->getMappedType($this),
        ];

        if ($type = $this->parseComment('type')) {
            $attributes['type'] = $type;
        }

        if (($length = $this->parameters->get('length')) && ($length != -1)) {
            $attributes['length'] = (int) $length;
        }
        if (($precision = $this->parameters->get('precision')) && ($precision != -1) && ($scale = $this->parameters->get('scale')) && ($scale != -1)) {
            $attributes['precision'] = (int) $precision;
            $attributes['scale'] = (int) $scale;
        }
        if (!$this->isNotNull()) {
            $attributes['nullable'] = true;
        } else {
            $attributes['nullable'] = false;
        }

        $attributes['options']['comment'] = $this->getComment(false);

        $defaultValue = $this->parameters->get('defaultValue');
        if ($defaultValue !== '' && $defaultValue !== 'NULL') {
            $defaultValue = trim($defaultValue, '"\'');
            if ($attributes['type'] === 'boolean') {
                $defaultValue = $defaultValue ? 'true' : 'false';
            }

            $attributes['options']['default'] = $defaultValue;
        }

        return $attributes;
    }

    public function write(WriterInterface $writer)
    {
        $comment = $this->getComment();

        $converter = $this->getDocument()->getFormatter()->getDatatypeConverter();
        $nativeType = $converter->getNativeType($converter->getMappedType($this));
        $filedName = $this->getPhpColumnName();

        $asAnnotation = $this->asAnnotation();

        $value = '';
        if (!is_null($this->getDefaultValue())) {
            switch ($nativeType) {
                case 'bool':
                    $map = [true => 'true', false => 'false'];
                    $value = ' = '.$map[(boolean) $this->getDefaultValue()];
                    break;
                case 'int':
                    $value = ' = '.intval($this->getDefaultValue());
                    break;
                case 'float':
                    $value = ' = '.floatval($this->getDefaultValue());
                    break;
                default:
                    $value = " = '{$this->getDefaultValue()}'";
            }
        }

        if ($asAnnotation['type'] == 'array') {
            $nativeType = $converter->getNativeType('array');

            if (!$this->isNotNull()) {
                $nativeType = 'null|'.$nativeType;
            } else {
                $value = ' = []';
            }
        }

        if ($asAnnotation['type'] == 'string_array') {
            $nativeType = $converter->getNativeType('string[]');

            if (!$this->isNotNull()) {
                $nativeType = 'null|'.$nativeType;
            } else {
                $value = ' = []';
            }
        }

        if ($asAnnotation['type'] == 'integer_array') {
            $nativeType = $converter->getNativeType('integer[]');

            if (!$this->isNotNull()) {
                $nativeType = 'null|'.$nativeType;
            } else {
                $value = ' = []';
            }
        }

        if (in_array($asAnnotation['type'], ['datetime', 'dateinterval', 'datetime_with_millisecond'])) {
            $nativeType = $converter->getDataType($asAnnotation['type']);
            if (!$this->isNotNull()) {
                $nativeType = 'null|'.$nativeType;
            }
        }

        $writer
            ->write('/**')
            ->writeIf($comment, $comment)
            ->write(' * @var '.$nativeType)
            ->writeIf($this->isPrimary,
                    ' * '.$this->getTable()->getAnnotation('Id'))
            ->write(' * '.$this->getTable()->getAnnotation('Column', $asAnnotation))
            ->writeIf($this->isAutoIncrement(),
                    ' * '.$this->getTable()->getAnnotation('GeneratedValue', ['strategy' => ($val = $this->parseComment('generator-strategy')) ? $val : 'AUTO']))
            ->write(' */')
            ->write('protected $'.$filedName.$value.';')
            ->write('')
        ;

        return $this;
    }

    public function writeArrayCollection(WriterInterface $writer, &$maxLen)
    {
        $fields = [];
        foreach ($this->foreigns as $foreign) {
            if ($foreign->getForeign()->getTable()->isManyToMany()) {
                // do not create entities for many2many tables
                continue;
            }

            if ($foreign->isManyToOne() && $foreign->parseComment('unidirectional') !== 'true') { // is ManyToOne

                if ($filedNameInversed = $foreign->getForeign()->parseComment('field-inversed')) {
                    $field = $filedNameInversed;
                } else {
                    $related = $this->getRelatedName($foreign);
                    $field = lcfirst(Inflector::pluralize($foreign->getOwningTable()->getModelName())).$related;
                }
                $fields[] = $field;
            }
        }

        foreach ($fields as $field) {
            $maxLen = max($maxLen, strlen($field));
        }

        foreach ($fields as $field) {
            $format = "\$this->%-{$maxLen}s = new %s();";
            $writer->write($format, $field, $this->getTable()->getCollectionClass(false));
        }

        return $this;
    }

    public function writeRelations(WriterInterface $writer)
    {
        $formatter = $this->getDocument()->getFormatter();
        // one to many references
        foreach ($this->foreigns as $foreign) {

            /**
             * @var \MwbExporter\Model\ForeignKey
             */
            if ($foreign->getForeign()->getTable()->isManyToMany()) {
                // do not create entities for many2many tables
                continue;
            }
            if ($foreign->isUnidirectional()) {
                // do not output mapping in foreign table when the unidirectional option is set
                continue;
            }

            $targetEntity = $foreign->getOwningTable()->getModelName();
            $targetEntityFQCN = $foreign->getOwningTable()->getModelNameAsFQCN($foreign->getReferencedTable()->getEntityNamespace());
            $mappedBy = $foreign->getReferencedTable()->getModelName();

            if ($filedNameMapped = $foreign->getForeign()->parseComment('field-mapped')) {
                $mappedBy = $filedNameMapped;
            }

            $cascade = ($d = $foreign->getForeign()->parseComment('cascade')) ? $d : $foreign->parseComment('cascade');
            $orphanRemoval = ($d = $foreign->getForeign()->parseComment('orphanRemoval')) ? $d : $foreign->parseComment('orphanRemoval');

            $annotationOptions = [
                'targetEntity'  => $targetEntityFQCN,
                'mappedBy'      => lcfirst($mappedBy),
                'cascade'       => $formatter->getCascadeOption($cascade),
                'fetch'         => $formatter->getFetchOption($foreign->parseComment('fetch')),
                'orphanRemoval' => $formatter->getBooleanOption($orphanRemoval),
            ];

            $joinColumnAnnotationOptions = [
                'name'                 => $foreign->getForeign()->getColumnName(),
                'referencedColumnName' => $foreign->getLocal()->getColumnName(),
                'onDelete'             => $formatter->getDeleteRule($foreign->getLocal()->getParameters()->get('deleteRule')),
                'nullable'             => !$foreign->getForeign()->isNotNull() ? true : false,
            ];

            $orderByAnnotationOptions = [];
            $orderBy = $foreign->getForeign()->parseComment('orderBy');

            if (!is_null($orderBy)) {
                $orderBy = $foreign->getForeign()->parseComment('orderBy');
                $orderByAnnotationOptions = [null => [$orderBy]];
            }

            //check for OneToOne or OneToMany relationship
            if ($foreign->isManyToOne()) { // is OneToMany

                $related = $this->getRelatedName($foreign);
                $nativeType = $this->getTable()->getCollectionClass(false);

                $comment = $foreign->getOwningTable()->getComment();
                $filedNameInversed = ($d = $foreign->getForeign()->parseComment('field-inversed')) ? $d : lcfirst(Inflector::pluralize($targetEntity)).$related;

                $writer
                    ->write('/**')
                    ->writeIf($comment, $comment)
                    ->write(' * Collection of '.$targetEntity.'.')
                    ->write(' * ')
                    ->write(' * @var '.$nativeType.'|'.$foreign->getOwningTable()->getNamespace().'[]')
                    ->write(' * '.$this->getTable()->getAnnotation('OneToMany', $annotationOptions))
                    ->write(' * '.$this->getTable()->getAnnotation('JoinColumn', $joinColumnAnnotationOptions));

                if ($orderByAnnotationOptions) {
                    $writer->write(' * '.$this->getTable()->getAnnotation('OrderBy', $orderByAnnotationOptions));
                }

                $writer
                    ->write(' */')
                    ->write('protected $'.$filedNameInversed.';')
                    ->write('')
                ;
            } else { // is OneToOne

                $comment = $foreign->getOwningTable()->getComment();
                $entityType = $foreign->getOwningTable()->getNamespace();

                $filedNameInversed = ($d = $foreign->getForeign()->parseComment('field-inversed')) ? $d : lcfirst($targetEntity);

                $writer
                    ->write('/**')
                    ->writeIf($comment, $comment)
                    ->write(' * @var '.$entityType)
                    ->write(' * '.$this->getTable()->getAnnotation('OneToOne', $annotationOptions))
                    ->write(' * '.$this->getTable()->getAnnotation('JoinColumn', $joinColumnAnnotationOptions))
                    ->write(' */')
                    ->write('protected $'.$filedNameInversed.';')
                    ->write('')
                ;
            }
        }
        // many to references
        if (null !== $this->local) {
            $targetEntity = $this->local->getReferencedTable()->getModelName();
            $targetEntityFQCN = $this->local->getReferencedTable()->getModelNameAsFQCN($this->local->getOwningTable()->getEntityNamespace());
            $inversedBy = $this->local->getOwningTable()->getModelName();

            if ($filedNameInversed = $this->local->getForeign()->parseComment('field-inversed')) {
                $inversedBy = $filedNameInversed;
            }

            $annotationOptions = [
                'targetEntity' => $targetEntityFQCN,
                'mappedBy'     => null,
                'inversedBy'   => $inversedBy,
                // 'cascade' => $formatter->getCascadeOption($this->local->parseComment('cascade')),
                // 'cascade' => $formatter->getCascadeOption($this->local->parseComment('cascade')),
                // 'fetch' => $formatter->getFetchOption($this->local->parseComment('fetch')),
                // 'orphanRemoval' => $formatter->getBooleanOption($this->local->parseComment('orphanRemoval')),
            ];

            $joinColumnAnnotationOptions = [
                'name'                 => $this->local->getForeign()->getColumnName(),
                'referencedColumnName' => $this->local->getLocal()->getColumnName(),
                'nullable'             => !$this->local->getForeign()->isNotNull() ? true : false,
            ];
            $onDelete = $formatter->getDeleteRule($this->local->getParameters()->get('deleteRule'));
            if ($onDelete) {
                $joinColumnAnnotationOptions['onDelete'] = $onDelete;
            }

            //check for OneToOne or ManyToOne relationship
            if ($this->local->isManyToOne()) { // is ManyToOne

                $related = $this->getManyToManyRelatedName($this->local->getReferencedTable()->getRawTableName(), $this->local->getForeign()->getColumnName());

                $refRelated = $this->local->getLocal()->getRelatedName($this->local);
                if ($this->local->isUnidirectional()) {
                    $annotationOptions['inversedBy'] = null;
                } elseif ($filedNameInversed) {
                    null;
                } else {
                    $annotationOptions['inversedBy'] = lcfirst(Inflector::pluralize($annotationOptions['inversedBy'])).$refRelated;
                }

                $comment = $this->local->getForeign()->getComment();

                $filedNameMapped = ($d = $this->local->getForeign()->parseComment('field-mapped')) ? $d : lcfirst($targetEntity).$related;
                $nullType = '';
                if (!$this->isNotNull()) {
                    $nullType = 'null|';
                }
                $writer
                    ->write('/**')
                    ->writeIf($comment, $comment)
                    ->write(' * @var '.$nullType.'\\'.$this->local->getReferencedTable()->getModelNameAsFQCN())
                    ->write(' * '.$this->getTable()->getAnnotation('ManyToOne', $annotationOptions))
                    ->write(' * '.$this->getTable()->getAnnotation('JoinColumn', $joinColumnAnnotationOptions))
                    ->write(' */')
                    ->write('protected $'.$filedNameMapped.';')
                    ->write('')
                ;
            } else { // is OneToOne

                if ($this->local->isUnidirectional()) {
                    $annotationOptions['inversedBy'] = null;
                } else {
                    $annotationOptions['inversedBy'] = lcfirst($annotationOptions['inversedBy']);
                }

                if ($filedNameInversed = $this->local->getForeign()->parseComment('field-inversed')) {
                    $annotationOptions['inversedBy'] = $filedNameInversed;
                }

                $annotationOptions['cascade'] = $formatter->getCascadeOption($this->local->parseComment('cascade'));

                $comment = $this->local->getForeign()->getComment();
                $filedNameMapped = ($d = $this->local->getForeign()->parseComment('field-mapped')) ? $d : lcfirst($targetEntity);

                if ($comment = trim($comment) && (substr($comment, -1) !== '.')) {
                    $comment .= '.';
                }
                $writer
                    ->write('/**')
                    ->writeIf($comment, $comment)
                    ->write(' * @var \\'.$this->local->getReferencedTable()->getModelNameAsFQCN())
                    ->write(' * '.$this->getTable()->getAnnotation('OneToOne', $annotationOptions))
                    ->write(' * '.$this->getTable()->getAnnotation('JoinColumn', $joinColumnAnnotationOptions))
                    ->write(' */')
                    ->write('protected $'.$filedNameMapped.';')
                    ->write('')
                ;
            }
        }

        return $this;
    }

    private function getColumnTypeData()
    {
        $converter = $this->getDocument()->getFormatter()->getDatatypeConverter();

        $asAnnotation = $this->asAnnotation();

        $defaultValue = null;

        switch ($asAnnotation['type']) {
            case 'array':
                    $nativeType = $converter->getNativeType('array');
                    $hint = 'array ';
                break;

            case 'datetime_with_millisecond':
            case 'datetime':
                    $nativeType = $converter->getDataType('datetime');
                    $hint = $nativeType.' ';
                    $defaultValue = ' = null';
                break;

            case 'dateinterval':
                    $nativeType = $converter->getDataType('dateinterval');
                    $hint = $nativeType.' ';
                    $defaultValue = ' = null';
                break;

            case 'string_array':
                    $nativeType = $converter->getNativeType('string[]');
                    $hint = 'array ';
                break;

            case 'integer_array':
                    $nativeType = $converter->getNativeType('integer[]');
                    $hint = 'array ';
                break;

            default;
        }

        if (is_null($defaultValue)) {
            if ($this->isNotNull()) {
                $defaultValue = '';
            } else {
                $defaultValue = ' = null'; //allow null for form working
            }
        }

        return isset($nativeType) ? [$nativeType, $hint, $defaultValue] : null;
    }

    public function writeGetterAndSetter(WriterInterface $writer, $methodName = null)
    {
        $table = $this->getTable();
        $converter = $this->getDocument()->getFormatter()->getDatatypeConverter();
        $nativeType = $converter->getNativeType($converter->getMappedType($this));

        $hint = null;
        $defaultValue = null;
        $nullType = '';

        if ($res = $this->getColumnTypeData()) {
            list($nativeType, $hint, $defaultValue) = $res;
            if (!$this->isNotNull()) {
                $nullType = 'null|';
            }
        }

        $propName = $varName = $this->getPhpColumnName();
        $funactionName = $this->columnNameBeautifier($this->getColumnName());
        if($methodName) {
            $funactionName = $methodName;
        }

        if ($this->parseComment('skip') == 'true') {
            return;
        }

        $writer
            // setter
            ->write('/**')
            ->write(' * Set the value of '.$varName.'.')
            ->write(' *')
            ->write(' * @param '.$nullType.$nativeType.' $'.$varName)
            ->write(' *')
            ->write(' * @return '.$table->getNamespace())
            ->write(' */')
            ->write('public function set'.$funactionName.'('.$hint.'$'.$varName.$defaultValue.')')
            ->write('{')
            ->indent()
                ->write('$this->'.$propName.' = $'.$varName.';')
                ->write('')
                ->write('return $this;')
            ->outdent()
            ->write('}')
            ->write('');

            // getter
        $writer->write('/**')
            ->write(' * Get the value of '.$varName.'.')
            ->write(' *')
            ->write(' * @return '.$nullType.$nativeType)
            ->write(' */')
            ->write('public function get'.$funactionName.'()')
            ->write('{')
            ->indent()
                ->write('return $this->'.$propName.';')
            ->outdent()
            ->write('}')
            ->write('')
        ;

        return $this;
    }

    public function writeRelationsGetterAndSetter(WriterInterface $writer)
    {
        $table = $this->getTable();
        // one to many references
        foreach ($this->foreigns as $foreign) {
            if ($foreign->getForeign()->getTable()->isManyToMany()) {
                // do not create entities for many2many tables
                continue;
            }
            if ($foreign->isUnidirectional()) {
                // do not output mapping in foreign table when the unidirectional option is set
                continue;
            }

            if ($foreign->isManyToOne()) { // is ManyToOne

                $related = $this->getRelatedName($foreign);
                $related_text = $this->getRelatedName($foreign, false);

                if ($v = $foreign->getForeign()->parseComment('field-inversed')) {
                    //v($v);
                    $propName =   $v;
                    $varName = Inflector::singularize($v);

                    $funactionName    = ucfirst(Inflector::singularize($propName));
                    $funactionGetName = ucfirst($v);
                } else {
                    $funactionName    = $this->columnNameBeautifier($foreign->getOwningTable()->getModelName()).$related;
                    $funactionGetName = $this->columnNameBeautifier(Inflector::pluralize($foreign->getOwningTable()->getModelName())).$related;

                    $propName =  lcfirst(Inflector::pluralize($foreign->getOwningTable()->getModelName())).$related;
                    $varName = lcfirst($foreign->getOwningTable()->getModelName());
                }

                if ($v = $foreign->getForeign()->parseComment('field-mapped')) {
                    $funactionSetName = ucfirst($v);
                } else {
                    $funactionSetName = ucfirst($table->getModelName());
                }
                $typeEntity = $foreign->getOwningTable()->getNamespace();

                $nullType = 'null|';
                $defaultValue = ' = null';

//                ->write('if ($'.$varName.') {')
//                    ->indent()
//                    ->writeIf(!$unidirectional, '$'.$varName.'->set'.$setterFunactionName.'($this);')
//                    ->outdent()
//                    ->write('}')
//                    ->write('$this->'.$propName.' = $'.$varName.';')
//                    ->write('')
//                    ->write('return $this;')

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Add '.trim($varName.' '.$related_text).' entity to collection (one to many).')
                    ->write(' *')
                    ->write(' * @param '.$nullType.$typeEntity.' $'.$varName)
                    ->write(' *')
                    ->write(' * @return '.$table->getNamespace())
                    ->write(' */')
                ->write('public function add'.$funactionName.'('.$typeEntity.' $'.$varName.$defaultValue.')')
                    ->write('{')
                    ->indent()
                        ->write('if ($'.$varName.') {')
                            ->indent()
                                ->write('$'.$varName.'->set'.$funactionSetName.'($this);')
                                ->write('$this->'.$propName.'[] = $'.$varName.';')

                            ->outdent()
                        ->write('}')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')

                    // remove
                 ->write('/**')
                    ->write(' * remove '.trim($varName.' '.$related_text).' entity from collection (one to many).')
                    ->write(' *')
                    ->write(' * @param '.$nullType.$typeEntity.' $'.$varName)
                    ->write(' *')
                    ->write(' * @return '.$table->getNamespace())
                    ->write(' */')
                    ->write('public function remove'.$funactionName.'('.$typeEntity.' $'.$varName.$defaultValue.')')
                        ->write('{')
                        ->indent()
                            ->write('if ($'.$varName.') {')
                            ->indent()
                                ->write('$this->'.$propName.'->removeElement($'.$varName.');')
                            ->outdent()
                            ->write('}')
                            ->write('')
                            ->write('return $this;')
                        ->outdent()
                        ->write('}')
                        ->write('')

                    // getter
                ->write('/**')
                    ->write(' * Get '.trim($propName.' '.$related_text).' collection (one to many).')
                    ->write(' *')
                    ->write(' * @return '.$table->getCollectionInterface().'|'.$typeEntity.'[]')
                    ->write(' */')
                    ->write('public function get'.$funactionGetName.'()')
                        ->write('{')
                        ->indent()
                            ->write('return $this->'.$propName.';')
                        ->outdent()
                        ->write('}')
                    ;
            } else { // OneToOne

                if ($filedNameInversed = $foreign->getForeign()->parseComment('field-inversed')) {
                    $funactionName = ucfirst(Inflector::singularize($filedNameInversed));
                    $propName = $varName = $filedNameInversed;
                } else {
                    $funactionName = ucfirst($this->columnNameBeautifier($foreign->getOwningTable()->getModelName()));
                    $propName = $varName  = lcfirst($foreign->getOwningTable()->getModelName());
                }

                $typeEntity = $foreign->getOwningTable()->getNamespace();

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Set '.$varName.' entity (one to one).')
                    ->write(' *')
                    ->write(' * @param '.$typeEntity.' $'.$propName)
                    ->write(' *')
                    ->write(' * @return '.$table->getNamespace())
                    ->write(' */')
                    ->write('public function set'.$funactionName.'('.$typeEntity.' $'.$varName.')')
                    ->write('{')
                    ->indent()
                        ->write('$this->'.$propName.' = $'.$varName.';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // getter
                    ->write('/**')
                    ->write(' * Get '.$varName.' entity (one to one).')
                    ->write(' *')
                    ->write(' * @return '.$typeEntity)
                    ->write(' */')
                    ->write('public function get'.$funactionName.'()')
                    ->write('{')
                    ->indent()
                        ->write('return $this->'.$propName.';')
                    ->outdent()
                    ->write('}')
                ;
            }
            $writer
                ->write('')
            ;
        }
        // many to one references
        if (null !== $this->local) {
            $unidirectional = $this->local->isUnidirectional();


            $nullType = '';
            if (!$this->isNotNull()) {
                $nullType = 'null|';
            }

            if ($this->local->isManyToOne()) { // is ManyToOne

                $related = $this->getManyToManyRelatedName($this->local->getReferencedTable()->getRawTableName(), $this->local->getForeign()->getColumnName());
                $related_text = $this->getManyToManyRelatedName($this->local->getReferencedTable()->getRawTableName(), $this->local->getForeign()->getColumnName(), false);

                if ($filedNameMapped = $this->local->getForeign()->parseComment('field-mapped')) {
                    $funactionName = ucfirst(Inflector::singularize($filedNameMapped));
                    $propName = $varName       = $filedNameMapped;
                } else {
                    $funactionName = $this->columnNameBeautifier($this->local->getReferencedTable()->getModelName()).$related;
                    $propName = $varName       = lcfirst($this->local->getReferencedTable()->getModelName()).$related;
                }

                if (!$this->isNotNull()) {
                    if (!$this->isNotNull()) {
                        $nullType = 'null|';
                    }
                }

                $defaultValue = ' = null';
                $typeEntity = $this->local->getReferencedTable()->getNamespace();

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Set '.trim($varName.' '.$related_text).' entity (many to one).')
                    ->write(' *')
                    ->write(' * @param '.$nullType.$typeEntity.' $'.$varName)
                    ->write(' *')
                    ->write(' * @return '.$table->getNamespace())
                    ->write(' */')
                    ->write('public function set'.$funactionName.'('.$typeEntity.' $'.$varName.$defaultValue.')')
                    ->write('{')
                    ->indent()
                        ->write('$this->'.$propName.' = $'.$varName.';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // getter
                    ->write('/**')
                    ->write(' * Get '.trim($varName.' '.$related_text).' entity (many to one).')
                    ->write(' *')
                    ->write(' * @return '.$nullType.$typeEntity)
                    ->write(' */')
                    ->write('public function get'.$funactionName.'()')
                    ->write('{')
                    ->indent()
                        ->write('return $this->'.$propName.';')
                    ->outdent()
                    ->write('}')
                    ->write('')
                ;
            } else { // OneToOne

                $typeEntity = $this->local->getReferencedTable()->getNamespace();
                $modelName = $this->local->getReferencedTable()->getModelName();

                if ($v = $this->local->getForeign()->parseComment('field-mapped')) {
                    $propName = $varName       = $v;
                    $funactionName = $this->columnNameBeautifier(Inflector::singularize($v));
                } else {
                    $propName = $varName       = lcfirst($this->local->getReferencedTable()->getModelName());
                    $funactionName = $this->columnNameBeautifier($this->local->getReferencedTable()->getModelName());
                }

                if ($v = $this->local->getForeign()->parseComment('field-inversed')) {
                    $setterFunactionName = $this->columnNameBeautifier($v);
                } else {
                    $setterFunactionName  = $this->columnNameBeautifier($this->local->getOwningTable()->getModelName());
                }

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Set '.$propName.' entity (one to one).')
                    ->write(' *')
                    ->write(' * @param '.$nullType.$typeEntity.' $'.$varName)
                    ->write(' *')
                    ->write(' * @return '.$table->getNamespace())
                    ->write(' */')
                    ->write('public function set'.$funactionName.'('.$typeEntity.' $'.$varName.' = null)')
                    ->write('{')
                    ->indent()
                        ->write('if ($'.$varName.') {')
                        ->indent()
                            ->writeIf(!$unidirectional, '$'.$varName.'->set'.$setterFunactionName.'($this);')
                        ->outdent()
                        ->write('}')
                        ->write('$this->'.$propName.' = $'.$varName.';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // getter
                    ->write('/**')
                    ->write(' * Get '.$propName.' entity (one to one).')
                    ->write(' *')
                    ->write(' * @return '.$nullType.$typeEntity)
                    ->write(' */')
                    ->write('public function get'.$funactionName.'()')
                    ->write('{')
                    ->indent()
                        ->write('return $this->'.$propName.';')
                    ->outdent()
                    ->write('}')
                    ->write('')
                ;
            }
        }

        return $this;
    }
}
