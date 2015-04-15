<?php

/*
 * The MIT License
 *
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

namespace MwbExporter\Object;

class Annotation extends Base
{
    /**
     * @var string
     */
    protected $annotation = null;

    /**
     * Constructor.
     *
     * @param string $annotation Annotation name
     * @param mixed  $content    Object content
     * @param array  $options    Object options
     */
    public function __construct($annotation, $content = null, $options = array())
    {
        parent::__construct($content, $options);
        $this->annotation = $annotation;
    }

    protected function decorateCode($code)
    {
        return $this->annotation.$code;
    }

    /**
     * (non-PHPdoc).
     *
     * @see \MwbExporter\Object\Base::asCode()
     */
    public function asCode($value)
    {
        if ($value instanceof self) {
            $value = (string) $value;
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_string($value)) {
            $value = str_replace('"', "'", $value);
            $value = '"'.$value.'"';
            $value = str_replace('=', '"="', $value);
        } elseif (is_array($value) && array_key_exists('comment', $value)) {
            $tmp = array();

            foreach ($value as $k => $v) {
                // skip null value
                if (null === $v) {
                    continue;
                }
                $v = $this->asCode($v);
                $tmp[] = sprintf('"%s"=%s', $k, $v);
            }

            $value = implode(', ', $tmp);
            $value = sprintf('{%s}', $value);

            return $value;
        } elseif (is_array($value)) {
            $tmp = array();
            $useKey = !$this->isKeysNumeric($value);

            foreach ($value as $k => $v) {
                // skip null value
                if (null === $v) {
                    continue;
                }
                $v = $this->asCode($v);
                if ($k == '') {
                    $tmp[] = $useKey ? sprintf('%s', $v) : $v;
                } else {
                    $tmp[] = $useKey ? sprintf('%s=%s', $k, $v) : $v;
                }
            }

            $value = implode(', ', $tmp);

            if ($useKey) {
                $value = sprintf('(%s)', $value);
            } else {
                $value = sprintf('{%s}', $value);
            }
        }

        return $value;
    }
}
