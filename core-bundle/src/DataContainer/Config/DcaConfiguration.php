<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\DataContainer\Config;

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class DcaConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('dca');
        $treeBuilder
            ->getRootNode()
            ->children()
                ->append($this->addConfigNode())
                ->append($this->addListNode())
                ->append($this->addFieldsNode())
            ->end()
        ;

        return $treeBuilder;
    }

    private function addConfigNode(): NodeDefinition
    {
        return (new TreeBuilder('config'))
            ->getRootNode()
            ->children()
                ->scalarNode('ptable')->end()
                ->booleanNode('dynamicPtable')->end()
                ->arrayNode('ctable')->scalarPrototype()->end()->end()
                ->scalarNode('dataContainer')->end()
                ->scalarNode('markAsCopy')->end()
                ->scalarNode('uploadPath')->end()
                ->scalarNode('validFileTypes')->end()
                ->scalarNode('editableFileTypes')->end()
                ->booleanNode('databaseAssisted')->end()
                ->booleanNode('closed')->end()
                ->booleanNode('notEditable')->end()
                ->booleanNode('notDeletable')->end()
                ->booleanNode('notSortable')->end()
                ->booleanNode('notCopyable')->end()
                ->booleanNode('notCreatable')->end()
                ->booleanNode('switchToEdit')->end()
                ->booleanNode('enableVersioning')->end()
                ->booleanNode('doNotCopyRecords')->end()
                ->booleanNode('doNotDeleteRecords')->end()
            ->end()
        ;
    }

    private function addListNode(): NodeDefinition
    {
        return (new TreeBuilder('list'))
            ->getRootNode()
            ->children()
                ->arrayNode('sorting')
                    ->children()
                        ->enumNode('mode')->values(range(0, 6))->end()
                        ->enumNode('flag')->values(range(0, 12))->end()
                        ->scalarNode('panel')->end()
                        ->arrayNode('fields')->scalarPrototype()->end()->end()
                        ->arrayNode('headerFields')->scalarPrototype()->end()->end()
                        ->scalarNode('icon')->end()
                        ->arrayNode('root')->scalarPrototype()->end()->end()
                        ->booleanNode('rootPaste')->end()
                        ->arrayNode('filter')->arrayPrototype()->end()->end()
                        ->booleanNode('disableGrouping')->end()
                    ->end()
                ->end()
                ->arrayNode('label')
                    ->children()
                        ->arrayNode('fields')->scalarPrototype()->end()->end()
                        ->booleanNode('showColumns')->end()
                        ->scalarNode('format')->end()
                        ->integerNode('maxCharacters')->end()
                    ->end()
                ->end()
                ->arrayNode('global_operations')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('href')->end()
                            ->scalarNode('icon')->end()
                            ->scalarNode('class')->end()
                            ->scalarNode('attributes')->end()
                            ->scalarNode('route')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('operations')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('href')->end()
                            ->scalarNode('icon')->end()
                            ->scalarNode('class')->end()
                            ->scalarNode('attributes')->end()
                            ->scalarNode('route')->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    private function addFieldsNode(): NodeDefinition
    {
        return (new TreeBuilder('fields'))
            ->getRootNode()
            ->arrayPrototype()
                ->children()
                    ->variableNode('default')->end()
                    ->booleanNode('exclude')->defaultTrue()->end()
                    ->booleanNode('search')->end()
                    ->booleanNode('sorting')->end()
                    ->booleanNode('filter')->end()
                    ->enumNode('flag')->values(range(0, 12))->end()
                    ->integerNode('length')->end()
                    ->scalarNode('inputType')->end()
                    ->arrayNode('options')->end()
                    ->scalarNode('foreignKey')->end()
                    ->scalarNode('explanation')->end()
                    ->arrayNode('eval')->end() // TODO define options
                    ->arrayNode('relation')->end() // TODO define options
                ->end()
            ->end()
        ;
    }
}
