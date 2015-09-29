<?php

namespace MwbExporter;

trait XmlPrettyTrait
{
    /**
     * @param       $xml
     * @param array $regulations
     *
     * @return mixed
     */
    private function prettyXml($xml, array $regulations = [])
    {
        $config = [
            'input-xml'         => true,
            'output-xml'        => true,
            'indent'            => true,
            'wrap'              => false,
            'indent-spaces'     => 4,
            'vertical-space'    => true,
            'sort-attributes'   => 'alpha',
            'indent-attributes' => false,
        ];

        $tidy = new \tidy;
        $tidy->parseString($xml, $config, 'utf8');
        $tidy->cleanRepair();
        $xml = $tidy.'';

        $regulationsBase = [
            //all
            '/( xmlns=| xmlns:xsi=| xsi:schemaLocation=)/'  => "\n       \\1",

            //model
            //'/ (repository-class=".*?")(name=".*?")(table=".*?")/' => "\n            \\1\n           \\2n           \\3",
            '/( repository-class=".*?")( name=".*?")( table=".*?")/' => "\n           \\1\n           \\2\n           \\3",
            '/(\s+<entity)/'               => "\n\\1",
            '/(\s+<\/entity>)/'            => "\n\\1\n",
            '/(\s+<field)/'                => "\n\\1",
            '/(\s+<one-to-one)/'           => "\n\\1",
            '/(\s+<many-to-one)/'          => "\n\\1",
            '/(\s+<one-to-many)/'          => "\n\\1",
            '/(\s+<many-to-many)/'         => "\n\\1",
            '/(\s+<id)/'                   => "\n\\1",
            '/(\s+<indexes)/'              => "\n\\1",
            '/(\s+<unique-constraints)/'   => "\n\\1",
            '/(\s+<unique-constraints)/'   => "\n\\1",
            '/(\s+<lifecycle-callbacks)/'  => "\n\\1",

            //validation
            //'/(xmlns=|xmlns:xsi=|xsi:schemaLocation=)/' => "\n        \\1",
            //'/(\s+<property name)/'                          => "\n\\1",
            '/(\s+<class)/'                                  => "\n\\1",
            '/(\s+<\/class>)/'                               => "\n\\1\n",
            "/(<class name=\"[^\"]*\">)(\s+)(<constraint)/"  => "\\1\n\\2\\3",
            '/(\s+<property)/'                               => "\n\\1",

            "/(    <\/property>)\n    (<property)/is"       => "\\1\n\n    \\2",
            "/(    <\/property>)\n    (<constraint)/is"       => "\\1\n\n    \\2",
            "/(    <\/constraint>)\n    (<property)/is"     => "\1\n\n    \2",
            "/(    <\/constraint>)\n    (<constraint)/is"   => "\\1\n\n    \\2",
        ];
        $regulationsBase = array_merge($regulationsBase, $regulations);

        foreach ($regulationsBase as $from => $to) {

            if (is_callable($to)) {
                $xml = preg_replace_callback($from, $to, $xml);
            } else {
                $xml = preg_replace($from, $to, $xml);
            }
        }

        $xml = preg_replace('/ \/>/','/>', $xml);

        return $xml;
    }
}