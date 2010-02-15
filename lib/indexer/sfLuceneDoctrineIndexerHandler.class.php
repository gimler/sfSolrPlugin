<?php
/*
 * This file is part of the sfLucenePlugin package
 * (c) 2007 Carl Vondrick <carlv@carlsoft.net>
 * (c) 2009 - Thomas Rabaix <thomas.rabaix@soleoweb.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * @package sfLucenePlugin
 * @subpackage Indexer
 * @author Carl Vondrick
 */

class sfLuceneDoctrineIndexerHandler extends sfLuceneModelIndexerHandler
{
  public function rebuildModel($name, $offset = null, $limit = null)
  {

    $options = $this->getSearch()->getParameter('models')->get($name);

    if(!$options)
    {
      throw new LogicException('The model \''.$name.'\' does not have any configurations');
    }
    
    $table = Doctrine :: getTable($name);
    $query = $this->getBaseQuery($name);

    if(is_numeric($offset) && is_numeric($limit))
    {
      $this->_rebuild($query, $offset, $limit);
      $query->free();
      $query->from($table->getComponentName());
    }
    else
    {

      $count = $query->count();
      $per   = $options->get('rebuild_limit');

      $totalPages = ceil($count / $per);

      for ($page = 0; $page < $totalPages; $page++)
      {
        $offset = $page * $per;
        $this->_rebuild(clone $query, $offset, $per);
      }
    }
  }

  public function getBaseQuery($model)
  {
    $table = Doctrine::getTable($model);

    if(method_exists($table, 'getLuceneQuery'))
    {
      $query = $table->getLuceneQuery($this->getSearch());
    }
    else
    {
      $query = $table->createQuery();
    }
    
    return $query;
  }

  public function getCount($model)
  {
    $query = $this->getBaseQuery($model);
    
    return $query->count();
  }

  protected function _rebuild($query, $offset, $limit)
  {

    $collection = $query->limit($limit)->offset($offset)->execute();

    $documents = array();
    $pks = array();
    foreach($collection as $record)
    {
      $doc = $this->getFactory()->getModel($record)->getDocument();

      if(!$doc)
      {
        $this->getSearch()->getEventDispatcher()->notify(
          new sfEvent($this, 'application.log', array(
            sprintf('invalid document %s [id:%s]: ', get_class($record), $record->identifier()),
            'priority' => sfLogger::ALERT
          ))
        );
        continue;
      }

      $documents[$doc->sfl_guid] = $doc;
      
      $field = $doc->getField('id');
      
      $pks[] = $field['value']['0'];
      
      unset($record);
    }

    $search_engine =  $this->getSearch()->getSearchService();

    try
    {
      $search_engine->deleteByMultipleIds(array_keys($documents));
      $search_engine->addDocuments($documents);
      $search_engine->commit();

      $this->getSearch()->getEventDispatcher()->notify(
         new sfEvent(
           $this,
           'indexer.log',
           array('indexing %s objects - primary keys [%s]', count($documents), implode(', ', $pks))
         )
      );
    }
    catch(Exception $e)
    {
       $this->getSearch()->getEventDispatcher()->notify(
         new sfEvent(
           $this,
           'indexer.log',
           array('indexing Failed indexing object - primary keys [%s]', implode(', ', $pks))
         )
      );
       
      $this->getSearch()->getEventDispatcher()->notify(
        new sfEvent($this, 'application.log', array(
          'indexing document fail : '.$e->getMessage(),
          'priority' => sfLogger::ALERT
        ))
      );
    }

    
    unset($collection);
  }
}