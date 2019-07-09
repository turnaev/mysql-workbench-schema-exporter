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

namespace MwbExporter\Model;

class ForeignKey extends Base
{
    /**
     * @var \MwbExporter\Model\Table
     */
    protected $referencedTable = null;

    /**
     * @var \MwbExporter\Model\Tabel
     */
    protected $owningTable = null;

    /**
     * @var \MwbExporter\Model\Column
     */
    protected $local = null;

    /**
     * @var \MwbExporter\Model\Column
     */
    protected $foreign = null;

    protected function init()
    {
        // iterate on foreign key configuration
        foreach ($this->node->value as $key => $node) {
            $attributes = $node->attributes();
            $this->parameters->set((string) $attributes['key'], (string) $node[0]);
        }
        // follow references to tables
        foreach ($this->node->link as $key => $node) {
            $attributes         = $node->attributes();
            $key                = (string) $attributes['key'];
            if ($key === 'referencedTable') {
                $this->referencedTable = $this->getDocument()->getReference()->get((string) $node);
            }
            if ($key === 'owner') {
                $this->owningTable = $this->getDocument()->getReference()->get((string) $node);
                $this->owningTable->injectRelation($this);
            }
        }

        $referencedColumn = $this->node->xpath("value[@key='referencedColumns']");
        $this->local = $this->getDocument()->getReference()->get((string) $referencedColumn[0]->link);

        $ownerColumn = $this->node->xpath("value[@key='columns']");
        $this->foreign = $this->getDocument()->getReference()->get((string) $ownerColumn[0]->link);

        // for doctrine2 annotations switch the local and the foreign
        // reference for a proper output
        $this->local->markAsForeignReference($this);
        $this->foreign->markAsLocalReference($this);
    }

    /**
     * Get the referenced table.
     *
     * @return \MwbExporter\Model\ForeignKey
     */
    public function getReferencedTable()
    {
        return $this->referencedTable;
    }

    /**
     * Get owner table.
     *
     * @return \MwbExporter\Model\Table
     */
    public function getOwningTable()
    {
        return $this->owningTable;
    }

    /**
     * Get local column.
     *
     * @return \MwbExporter\Model\Column
     */
    public function getLocal()
    {
        return $this->local;
    }

    /**
     * Get foreign column.
     *
     * @return \MwbExporter\Model\Column
     */
    public function getForeign()
    {
        return $this->foreign;
    }

    /**
     * get the a boolean option for a relation.
     *
     * @param $booleanValue string boolean option (true or false)
     *
     * @return bool or null, if booleanValue was invalid
     */
    private function getBooleanOption($booleanValue)
    {
        if ($booleanValue !== null) {
            switch (strtolower($booleanValue)) {
                case 'true':
                    return true;
                case 'false':
                    return false;
                default:
                    return (bool) $booleanValue;
            }
        }
    }

    /**
     * Check relation if it is a many to one relation.
     *
     * @return bool
     */
    public function isManyToOne()
    {
        $o2o = $this->foreign->parseComment('o2o');

        if ($o2o !== null) {
            $isMany = !(bool) $this->getBooleanOption($o2o);
        } else {
            $isMany = (bool) $this->parameters->get('many');
        }

        return $isMany;
    }

    /**
     * Check relation if it is unidirectional
     *
     * @return bool
     */
    public function isUnidirectional()
    {
        $o = $this->foreign->parseComment('unidirectional');
        $is = (bool) $this->getBooleanOption($o);
        return $is;
    }
}
