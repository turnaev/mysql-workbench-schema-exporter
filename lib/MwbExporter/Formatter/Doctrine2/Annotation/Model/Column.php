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

        if($type = $this->parseComment('type')) {
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
        }  else {
            $attributes['nullable'] = false;
        }

        if ($this->parameters->get('comment')) {

            $attributes['options']["comment"] = $this->getComment(false);
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
        if(!is_null($this->getDefaultValue())) {

            switch($nativeType) {
                case 'boolean':
                    $map = [true=>'true', false=>'false'];
                    $value = " = ".$map[(boolean)$this->getDefaultValue()];
                    break;
                case 'integer':
                    $value = " = ".intval($this->getDefaultValue());
                    break;
                case 'float':
                    $value = " = ".floatval($this->getDefaultValue());
                    break;
                default:
                    $value = " = '{$this->getDefaultValue()}'";
            }

        }

        if($asAnnotation['type'] == 'array') {
            $nativeType = $converter->getNativeType('array');
            $value = ' = []';
        }

        $writer
            ->write('/**')
            ->writeIf($comment, $comment)
            ->write(' * @var '.$nativeType)
            ->writeIf($this->isPrimary,
                    ' * '.$this->getTable()->getAnnotation('Id'))
            ->write(' * '.$this->getTable()->getAnnotation('Column', $asAnnotation))
            ->writeIf($this->isAutoIncrement(),
                    ' * '.$this->getTable()->getAnnotation('GeneratedValue', ['strategy' => 'AUTO']))
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

                if($filedNameInversed = $foreign->getForeign()->parseComment('field-inversed')) {
                    $field = $filedNameInversed;
                } else {
                    $related = $this->getRelatedName($foreign);
                    $field = lcfirst(Inflector::pluralize($foreign->getOwningTable()->getModelName())).$related;
                }
                $fields[] = $field;
            }
        }

        foreach($fields as $field) {
            $maxLen = max($maxLen, strlen($field));
        }

        foreach($fields as $field) {
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
             * @var \MwbExporter\Model\ForeignKey $foreign
             */
            if ($foreign->getForeign()->getTable()->isManyToMany()) {
                // do not create entities for many2many tables
                continue;
            }
            if ($foreign->parseComment('unidirectional') === 'true') {
                // do not output mapping in foreign table when the unidirectional option is set
                continue;
            }

            $targetEntity = $foreign->getOwningTable()->getModelName();
            $targetEntityFQCN = $foreign->getOwningTable()->getModelNameAsFQCN($foreign->getReferencedTable()->getEntityNamespace());
            $mappedBy = $foreign->getReferencedTable()->getModelName();

            if($filedNameMapped = $foreign->getForeign()->parseComment('field-mapped')) {
                $mappedBy = $filedNameMapped;
            }

            $cascade = ($d = $foreign->getForeign()->parseComment('cascade')) ? $d:$foreign->parseComment('cascade');
            $orphanRemoval = ($d = $foreign->getForeign()->parseComment('orphanRemoval')) ? $d:$foreign->parseComment('orphanRemoval');

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

            if(!is_null($orderBy)) {

                $orderBy = $foreign->getForeign()->parseComment('orderBy');
                $orderByAnnotationOptions=[null=>[$orderBy]];
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
                    ->write(' * collection of '.$targetEntity)
                    ->write(' * @var '.$nativeType.'|'.$foreign->getOwningTable()->getNamespace().'[]')
                    ->write(' * ')
                    ->write(' * '.$this->getTable()->getAnnotation('OneToMany', $annotationOptions))
                    ->write(' * '.$this->getTable()->getAnnotation('JoinColumn', $joinColumnAnnotationOptions));

                if($orderByAnnotationOptions) {
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

            if($filedNameInversed = $this->local->getForeign()->parseComment('field-inversed')) {
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
                'onDelete'             => $formatter->getDeleteRule($this->local->getParameters()->get('deleteRule')),
                'nullable'             => !$this->local->getForeign()->isNotNull() ? true : false,
            ];

            //check for OneToOne or ManyToOne relationship
            if ($this->local->isManyToOne()) { // is ManyToOne

                $related = $this->getManyToManyRelatedName($this->local->getReferencedTable()->getRawTableName(), $this->local->getForeign()->getColumnName());
                $refRelated = $this->local->getLocal()->getRelatedName($this->local);
                if ($this->local->parseComment('unidirectional') === 'true') {
                    $annotationOptions['inversedBy'] = null;
                } else if($filedNameInversed) {
                    null;
                } else {
                    $annotationOptions['inversedBy'] = lcfirst(Inflector::pluralize($annotationOptions['inversedBy'])) . $refRelated;
                }

                $comment = $this->local->getForeign()->getComment();

                $filedNameMapped = ($d = $this->local->getForeign()->parseComment('field-mapped')) ? $d : lcfirst($targetEntity).$related;

                $writer
                    ->write('/**')
                    ->writeIf($comment, $comment)
                    ->write(' * @var \\'.$this->local->getReferencedTable()->getModelNameAsFQCN())
                    ->write(' * '.$this->getTable()->getAnnotation('ManyToOne', $annotationOptions))
                    ->write(' * '.$this->getTable()->getAnnotation('JoinColumn', $joinColumnAnnotationOptions))
                    ->write(' */')
                    ->write('protected $'.$filedNameMapped.';')
                    ->write('')
                ;
            } else { // is OneToOne

                if ($this->local->parseComment('unidirectional') === 'true') {
                    $annotationOptions['inversedBy'] = null;
                } else {
                    $annotationOptions['inversedBy'] = lcfirst($annotationOptions['inversedBy']);
                }

                if($filedNameInversed = $this->local->getForeign()->parseComment('field-inversed')) {
                    $annotationOptions['inversedBy'] = $filedNameInversed;
                }

                $annotationOptions['cascade'] = $formatter->getCascadeOption($this->local->parseComment('cascade'));

                $comment = $this->local->getForeign()->getComment();

                $filedNameMapped = ($d = $this->local->getForeign()->parseComment('field-mapped')) ? $d : lcfirst($targetEntity);

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

    public function writeGetterAndSetter(WriterInterface $writer)
    {
        $table = $this->getTable();
        $converter = $this->getDocument()->getFormatter()->getDatatypeConverter();
        $nativeType = $converter->getNativeType($converter->getMappedType($this));

        $hint = null;
        $defaultValue = null;

        $asAnnotation = $this->asAnnotation();
        if($asAnnotation['type'] == 'array') {

            $nativeType = $converter->getNativeType('array');
            $hint = 'array ';

            if($this->isNotNull()) {
                $defaultValue = ' = []';
            } else {
                $defaultValue = ' = null';
            }
        }

        if($asAnnotation['type'] == 'datetime') {
            $nativeType = $converter->getDataType('datetime');

            $nativeType = '\DateTime';
            $hint = $nativeType.' ';

            if(!$this->isNotNull()) {
                $defaultValue = ' = null';
            }
        }


        $writer
            // setter
            ->write('/**')
            ->write(' * Set the value of '.$this->getPhpColumnName().'.')
            ->write(' *')
            ->write(' * @param '.$nativeType.' $'.$this->getPhpColumnName())
            ->write(' * @return '.$table->getNamespace())
            ->write(' */')
            ->write('public function set'.$this->columnNameBeautifier($this->getColumnName()).'('.$hint.'$'.$this->getPhpColumnName().$defaultValue.')')
            ->write('{')
            ->indent()
                ->write('$this->'.$this->getPhpColumnName().' = $'.$this->getPhpColumnName().';')
                ->write('')
                ->write('return $this;')
            ->outdent()
            ->write('}')
            ->write('');

            // getter
        $writer->write('/**')
            ->write(' * Get the value of '.$this->getPhpColumnName().'.')
            ->write(' *')
            ->write(' * @return '.$nativeType)
            ->write(' */')
            ->write('public function get'.$this->columnNameBeautifier($this->getColumnName()).'()')
            ->write('{')
            ->indent()
                ->write('return $this->'.$this->getPhpColumnName().';')
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
            if ($foreign->parseComment('unidirectional') === 'true') {
                // do not output mapping in foreign table when the unidirectional option is set
                continue;
            }

            if ($foreign->isManyToOne()) { // is ManyToOne

                $related = $this->getRelatedName($foreign);
                $related_text = $this->getRelatedName($foreign, false);

                if($filedNameInversed = $foreign->getForeign()->parseComment('field-inversed')) {

                    $funactionNamePart    = ucfirst(Inflector::singularize($filedNameInversed));
                    $funactionGetNamePart = ucfirst($filedNameInversed);

                    $codeAddPart       = $filedNameInversed;
                    $codeRemovePart    = $filedNameInversed;
                    $codeGetPart       = $filedNameInversed;

                } else {

                    $funactionNamePart    = $this->columnNameBeautifier($foreign->getOwningTable()->getModelName()).$related;
                    $funactionGetNamePart = $this->columnNameBeautifier(Inflector::pluralize($foreign->getOwningTable()->getModelName())).$related;

                    $codeAddPart       = lcfirst(Inflector::pluralize($foreign->getOwningTable()->getModelName())).$related;
                    $codeRemovePart    = lcfirst(Inflector::pluralize($foreign->getOwningTable()->getModelName())).$related;
                    $codeGetPart       = lcfirst(Inflector::pluralize($foreign->getOwningTable()->getModelName())).$related;
                }

                if($filedNameMapped = $foreign->getForeign()->parseComment('field-mapped')) {
                    $codeSetMappedPart = ucfirst($filedNameMapped);
                } else {
                    $codeSetMappedPart = ucfirst($table->getModelName());
                }

                $typeEntity = $foreign->getOwningTable()->getNamespace();
                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Add '.trim($foreign->getOwningTable()->getModelName().' '.$related_text). ' entity to collection (one to many).')
                    ->write(' *')
                    ->write(' * @param '.$typeEntity.' $'.lcfirst($foreign->getOwningTable()->getModelName()))
                    ->write(' * @return '.$table->getNamespace())
                    ->write(' */')
                ->write('public function add'.$funactionNamePart.'('.$typeEntity.' $'.lcfirst($foreign->getOwningTable()->getModelName()).')')
                    ->write('{')
                    ->indent()
                        ->write('$'.lcfirst($foreign->getOwningTable()->getModelName()).'->set'.$codeSetMappedPart.'($this);')
                        ->write('$this->'.$codeAddPart.'[] = $'.lcfirst($foreign->getOwningTable()->getModelName()).';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')

                    // remove
                 ->write('/**')
                    ->write(' * remove '.trim($foreign->getOwningTable()->getModelName().' '.$related_text). ' entity from collection (one to many).')
                    ->write(' *')
                    ->write(' * @param '.$typeEntity.' $'.lcfirst($foreign->getOwningTable()->getModelName()))
                    ->write(' * @return '.$table->getNamespace())
                    ->write(' */')
                    ->write('public function remove'.$funactionNamePart.'('.$typeEntity.' $'.lcfirst($foreign->getOwningTable()->getModelName()).')')
                        ->write('{')
                        ->indent()
                            ->write('$this->'.$codeRemovePart.'->removeElement($'.lcfirst($foreign->getOwningTable()->getModelName()).');')
                            ->write('$'.lcfirst($foreign->getOwningTable()->getModelName()).'->set'.$codeSetMappedPart.'(null);')
                            ->write('')
                            ->write('return $this;')
                        ->outdent()
                        ->write('}')
                        ->write('')

                    // getter
                ->write('/**')
                    ->write(' * Get '.trim($foreign->getOwningTable()->getModelName().' '.$related_text).' entity collection (one to many).')
                    ->write(' *')
                    ->write(' * @return '.$table->getCollectionInterface().'|'.$foreign->getOwningTable()->getNamespace().'[]')
                    ->write(' */')
                    ->write('public function get'.$funactionGetNamePart.'()')
                        ->write('{')
                        ->indent()
                            ->write('return $this->'.$codeGetPart.';')
                        ->outdent()
                        ->write('}')
                    ;

            } else { // OneToOne


                if($filedNameInversed = $foreign->getForeign()->parseComment('field-inversed')) {

                    $funactionNamePart    = ucfirst(Inflector::singularize($filedNameInversed));

                    $codeSetPart       = $filedNameInversed;
                    $codeGetPart       = $filedNameInversed;

                } else {

                    $funactionNamePart    = ucfirst($this->columnNameBeautifier($foreign->getOwningTable()->getModelName()));

                    $codeSetPart       = lcfirst($foreign->getOwningTable()->getModelName());
                    $codeGetPart       = lcfirst($foreign->getOwningTable()->getModelName());
                }

                $typeEntity = $foreign->getOwningTable()->getNamespace();

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Set '.$funactionNamePart.' entity (one to one).')
                    ->write(' *')
                    ->write(' * @param '.$typeEntity.' $'.lcfirst($foreign->getOwningTable()->getModelName()))
                    ->write(' * @return '.$table->getNamespace())
                    ->write(' */')
                    ->write('public function set'.$funactionNamePart.'('.$typeEntity.' $'.$codeSetPart.')')
                    ->write('{')
                    ->indent()
                        ->write('$this->'.$codeSetPart.' = $'.$codeSetPart.';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // getter
                    ->write('/**')
                    ->write(' * Get '.$funactionNamePart.' entity (one to one).')
                    ->write(' *')
                    ->write(' * @return '.$foreign->getOwningTable()->getNamespace())
                    ->write(' */')
                    ->write('public function get'.$funactionNamePart.'()')
                    ->write('{')
                    ->indent()
                        ->write('return $this->'.$codeGetPart.';')
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
            $unidirectional = ($this->local->parseComment('unidirectional') === 'true');

            if ($this->local->isManyToOne()) { // is ManyToOne

                $related = $this->getManyToManyRelatedName($this->local->getReferencedTable()->getRawTableName(), $this->local->getForeign()->getColumnName());
                $related_text = $this->getManyToManyRelatedName($this->local->getReferencedTable()->getRawTableName(), $this->local->getForeign()->getColumnName(), false);

                if($filedNameMapped = $this->local->getForeign()->parseComment('field-mapped')) {
                    $funactionNamePart = ucfirst(Inflector::singularize($filedNameMapped));
                    $codeSetPart       = $filedNameMapped;
                    $codeGetPart       = $filedNameMapped;

                } else {
                    $funactionNamePart = $this->columnNameBeautifier($this->local->getReferencedTable()->getModelName()).$related;
                    $codeSetPart       = lcfirst($this->local->getReferencedTable()->getModelName()).$related;
                    $codeGetPart       = lcfirst($this->local->getReferencedTable()->getModelName()).$related;
                }

                $typeEntity = $this->local->getReferencedTable()->getNamespace();

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Set '.trim($this->local->getReferencedTable()->getModelName().' '.$related_text).' entity (many to one).')
                    ->write(' *')
                    ->write(' * @param '.$typeEntity.' $'.lcfirst($this->local->getReferencedTable()->getModelName()))
                    ->write(' * @return '.$table->getNamespace())
                    ->write(' */')
                    ->write('public function set'.$funactionNamePart.'('.$typeEntity.' $'.lcfirst($this->local->getReferencedTable()->getModelName()).' = null)')
                    ->write('{')
                    ->indent()
                        ->write('$this->'.$codeSetPart.' = $'.lcfirst($this->local->getReferencedTable()->getModelName()).';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // getter
                    ->write('/**')
                    ->write(' * Get '.trim($this->local->getReferencedTable()->getModelName().' '.$related_text).' entity (many to one).')
                    ->write(' *')
                    ->write(' * @return '.$this->local->getReferencedTable()->getNamespace())
                    ->write(' */')
                    ->write('public function get'.$funactionNamePart.'()')
                    ->write('{')
                    ->indent()
                        ->write('return $this->'.$codeGetPart.';')
                    ->outdent()
                    ->write('}')
                    ->write('')
                ;
            } else { // OneToOne

                $typeEntity = $this->local->getReferencedTable()->getNamespace();

                if($filedNameMapped = $this->local->getForeign()->parseComment('field-mapped')) {

                    $funactionNamePart = ucfirst(Inflector::singularize($filedNameMapped));
                    $codeSetPart       = $filedNameMapped;
                    $codeGetPart       = $filedNameMapped;

                } else {
                    $funactionNamePart = $this->columnNameBeautifier($this->local->getReferencedTable()->getModelName());
                    $codeSetPart       = lcfirst($this->local->getReferencedTable()->getModelName());
                    $codeGetPart       = lcfirst($this->local->getReferencedTable()->getModelName());
                }

                $writer
                    // setter
                    ->write('/**')
                    ->write(' * Set '.$this->local->getReferencedTable()->getModelName().' entity (one to one).')
                    ->write(' *')
                    ->write(' * @param '.$this->local->getReferencedTable()->getNamespace().' $'.$codeSetPart)
                    ->write(' * @return '.$table->getNamespace())
                    ->write(' */')
                    ->write('public function set'.$funactionNamePart.'('.$typeEntity.' $'.$codeSetPart.' = null)')
                    ->write('{')
                    ->indent()
                        ->writeIf(!$unidirectional, '$'.$codeSetPart.'->set'.$this->columnNameBeautifier($this->local->getOwningTable()->getModelName()).'($this);')
                        ->write('$this->'.$codeSetPart.' = $'.$codeSetPart.';')
                        ->write('')
                        ->write('return $this;')
                    ->outdent()
                    ->write('}')
                    ->write('')
                    // getter
                    ->write('/**')
                    ->write(' * Get '.$this->local->getReferencedTable()->getModelName().' entity (one to one).')
                    ->write(' *')
                    ->write(' * @return '.$this->local->getReferencedTable()->getNamespace())
                    ->write(' */')
                    ->write('public function get'.$funactionNamePart.'()')
                    ->write('{')
                    ->indent()
                        ->write('return $this->'.$codeGetPart.';')
                    ->outdent()
                    ->write('}')
                    ->write('')
                ;
            }
        }

        return $this;
    }
}
