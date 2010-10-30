<?php

namespace DoctrineExtensions\Tree\Repository;

use Doctrine\ORM\EntityRepository,
    Doctrine\ORM\Query,
    DoctrineExtensions\Tree\Node,
    DoctrineExtensions\Tree\Configuration;

/**
 * The TreeNodeRepository has some useful functions
 * to interact with tree.
 * 
 * Some Tree logic is copied from -
 * CakePHP: Rapid Development Framework (http://cakephp.org)
 * 
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @package DoctrineExtensions.Tree.Repository
 * @subpackage TreeNodeRepository
 * @link http://www.gediminasm.org
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class TreeNodeRepository extends EntityRepository
{   
    /**
     * Get the Tree path of Nodes by given $node
     * 
     * @param Node $node
     * @return array - list of Nodes in path
     */
    public function getPath(Node $node)
    {
        $result = array();
        $meta = $this->_em->getClassMetadata($this->_entityName);
        $config = $node->getTreeConfiguration();
        
        $left = $meta->getReflectionProperty($config->getLeftField())
            ->getValue($node);
        $right = $meta->getReflectionProperty($config->getRightField())
            ->getValue($node);
        if (!empty($left) && !empty($right)) {
            $qb = $this->_em->createQueryBuilder();
            $qb->select('node')
                ->from($this->_entityName, 'node')
                ->where('node.' . $config->getLeftField() . " <= :left")
                ->andWhere('node.' . $config->getRightField() . " >= :right")
                ->orderBy('node.' . $config->getLeftField(), 'ASC');
            $q = $qb->getQuery();
            $result = $q->execute(
                compact('left', 'right'),
                Query::HYDRATE_OBJECT
            );
        }
        return $result;
    }
    
    /**
     * Counts the children of given TreeNode
     * 
     * @param Node $node - if null counts all records in tree
     * @param boolean $direct - true to count only direct children
     * @return integer
     */ 
    public function childCount($node = null, $direct = false)
    {
        $count = 0;
        $meta = $this->_em->getClassMetadata($this->_entityName);
        $nodeId = $meta->getSingleIdentifierFieldName();
        if ($node instanceof Node) {
            $config = $node->getTreeConfiguration();
            if ($direct) {
                $id = $meta->getReflectionProperty($nodeId)->getValue($node);
                $qb = $this->_em->createQueryBuilder();
                $qb->select('COUNT(node.' . $nodeId . ')')
                    ->from($this->_entityName, 'node')
                    ->where('node.' . $config->getParentField() . ' = ' . $id);
                    
                $q = $qb->getQuery();
                $count = intval($q->getSingleScalarResult());
            } else {
                $left = $meta->getReflectionProperty($config->getLeftField())
                    ->getValue($node);
                $right = $meta->getReflectionProperty($config->getRightField())
                    ->getValue($node);
                if (!empty($left) && !empty($right)) {
                    $count = ($right - $left - 1) / 2;
                }
            }
        } else {
            $dql = "SELECT COUNT(node.{$nodeId}) FROM {$this->_entityName} node";
            if ($direct) {
                $node = new $this->_entityName();
                $config = $node->getTreeConfiguration();
                $dql .= ' WHERE node.' . $config->getParentField() . ' IS NULL';
            }
            $q = $this->_em->createQuery($dql);
            $count = intval($q->getSingleScalarResult());
        }
        return $count;
    }
    
    /**
     * Get list of children followed by given $node
     * 
     * @param Node $node - if null, all tree nodes will be taken
     * @param boolean $direct - true to take only direct children
     * @param string $sortByField - field name to sort by
     * @param string $direction - sort direction : "ASC" or "DESC"
     * @return array - list of given $node children, null on failure
     */
    public function children($node = null, $direct = false, $sortByField = null, $direction = 'ASC')
    {
        $meta = $this->_em->getClassMetadata($this->_entityName);
        $qb = $this->_em->createQueryBuilder();
        $qb->select('node')
            ->from($this->_entityName, 'node');
        if ($node instanceof Node) {
            $config = $node->getTreeConfiguration();
            if ($direct) {
                $nodeId = $meta->getSingleIdentifierFieldName();
                $id = $meta->getReflectionProperty($nodeId)->getValue($node);
                $qb->where('node.' . $config->getParentField() . ' = ' . $id);
            } else {
                $left = $meta->getReflectionProperty($config->getLeftField())
                    ->getValue($node);
                $right = $meta->getReflectionProperty($config->getRightField())
                    ->getValue($node);
                if (!empty($left) && !empty($right)) {
                    $qb->where('node.' . $config->getRightField() . " < {$right}")
                        ->andWhere('node.' . $config->getLeftField() . " > {$left}");
                }
            }
        } else {
            $node = new $this->_entityName();
            $config = $node->getTreeConfiguration();
            if ($direct) {
                $qb->where('node.' . $config->getParentField() . ' IS NULL');
            }
        }
        if (!$sortByField) {
            $qb->orderBy('node.' . $config->getLeftField(), 'ASC');
        } else {
            if ($meta->hasField($sortByField) && in_array(strtolower($direction), array('asc', 'desc'))) {
                $qb->orderBy('node.' . $sortByField, $direction);
            } else {
                throw RuntimeException("Invalid sort options specified: field - {$sortByField}, direction - {$direction}");
            }
        }
        $q = $qb->getQuery();
        $q->useResultCache(false);
        $q->useQueryCache(false);
        return $q->getResult(Query::HYDRATE_OBJECT);
    }
    
    /**
     * Move the node down in the same level
     * 
     * @param Node $node
     * @param mixed $number
     *         integer - number of positions to shift
     *         boolean - true shift till last position
     * @throws Exception if something fails in transaction
     * @return boolean - true if shifted
     */
    public function moveDown(Node $node, $number = 1)
    {
        if (!$number) {
            return false;
        }
        
        $meta = $this->_em->getClassMetadata($this->_entityName);
        $config = $node->getTreeConfiguration();
        $parent = $meta->getReflectionProperty($config->getParentField())
            ->getValue($node);
        $right = $meta->getReflectionProperty($config->getRightField())
            ->getValue($node);
        
        if ($parent) {
            $this->_em->refresh($parent);
            $parentRight = $meta->getReflectionProperty($config->getRightField())
                ->getValue($parent);
            if (($right + 1) == $parentRight) {
                return false;
            }
        }
        $dql = "SELECT node FROM {$this->_entityName} node";
        $dql .= ' WHERE node.' . $config->getLeftField() . ' = ' . ($right + 1);
        $q = $this->_em->createQuery($dql);
        $q->setMaxResults(1);
        $result = $q->getResult(Query::HYDRATE_OBJECT);
        $nextSiblingNode = count($result) ? array_shift($result) : null;
        
        if (!$nextSiblingNode) {
            return false;
        }
        
        // this one is very important because if em is not cleared
        // it loads node from memory without refresh
        $this->_em->refresh($nextSiblingNode);
        
        $left = $meta->getReflectionProperty($config->getLeftField())
            ->getValue($node);
        $nextLeft = $meta->getReflectionProperty($config->getLeftField())
            ->getValue($nextSiblingNode);
        $nextRight = $meta->getReflectionProperty($config->getRightField())
            ->getValue($nextSiblingNode);
        $edge = $this->_getTreeEdge($config);
        // process updates in transaction
        $this->_em->getConnection()->beginTransaction();
        try {            
            $this->_sync($config, $edge - $left + 1, '+', 'BETWEEN ' . $left . ' AND ' . $right);
            $this->_sync($config, $nextLeft - $left, '-', 'BETWEEN ' . $nextLeft . ' AND ' . $nextRight);
            $this->_sync($config, $edge - $left - ($nextRight - $nextLeft), '-', ' > ' . $edge);
            $this->_em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->_em->close();
            $this->_em->getConnection()->rollback();
            throw $e;
        }
        if (is_int($number)) {
            $number--;
        }
        if ($number) {
            $this->_em->refresh($node);
            $this->moveDown($node, $number);
        }
        return true;
    }
    
    /**
     * Move the node up in the same level
     * 
     * @param Node $node
     * @param mixed $number
     *         integer - number of positions to shift
     *         boolean - true shift till first position
     * @throws Exception if something fails in transaction
     * @return boolean - true if shifted
     */
    public function moveUp(Node $node, $number = 1)
    {
        if (!$number) {
            return false;
        }
        
        $meta = $this->_em->getClassMetadata($this->_entityName);
        $config = $node->getTreeConfiguration();
        $parent = $meta->getReflectionProperty($config->getParentField())
            ->getValue($node);
            
        $left = $meta->getReflectionProperty($config->getLeftField())
            ->getValue($node);
        if ($parent) {
            $this->_em->refresh($parent);
            $parentLeft = $meta->getReflectionProperty($config->getLeftField())
                ->getValue($parent);
            if (($left - 1) == $parentLeft) {
                return false;
            }
        }
        
        $dql = "SELECT node FROM {$this->_entityName} node";
        $dql .= ' WHERE node.' . $config->getRightField() . ' = ' . ($left - 1);
        $q = $this->_em->createQuery($dql);
        $q->setMaxResults(1);
        $result = $q->getResult(Query::HYDRATE_OBJECT);
        $previousSiblingNode = count($result) ? array_shift($result) : null;
        
        if (!$previousSiblingNode) {
            return false;
        }
        // this one is very important because if em is not cleared
        // it loads node from memory without refresh
        $this->_em->refresh($previousSiblingNode);
        
        $right = $meta->getReflectionProperty($config->getRightField())
            ->getValue($node);
        $previousLeft = $meta->getReflectionProperty($config->getLeftField())
            ->getValue($previousSiblingNode);
        $previousRight = $meta->getReflectionProperty($config->getRightField())
            ->getValue($previousSiblingNode);
        $edge = $this->_getTreeEdge($config);
        // process updates in transaction
        $this->_em->getConnection()->beginTransaction();
        try {
            $this->_sync($config, $edge - $previousLeft +1, '+', 'BETWEEN ' . $previousLeft . ' AND ' . $previousRight);
            $this->_sync($config, $left - $previousLeft, '-', 'BETWEEN ' .$left . ' AND ' . $right);
            $this->_sync($config, $edge - $previousLeft - ($right - $left), '-', '> ' . $edge);
            $this->_em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->_em->close();
            $this->_em->getConnection()->rollback();
            throw $e;
        }
        if (is_int($number)) {
            $number--;
        }
        if ($number) {
            $this->_em->refresh($node);
            $this->moveUp($node, $number);
        }
        return true;
    }
    
    /**
     * Reorders the sibling nodes and child nodes by given $node,
     * according to the $sortByField and $direction specified
     * 
     * @param Node $node - null to reorder all tree
     * @param string $sortByField - field name to sort by
     * @param string $direction - sort direction : "ASC" or "DESC"
     * @param boolean $verify - true to verify tree first
     * @return boolean - true on success
     */
    public function reorder($node = null, $sortByField = null, $direction = 'ASC', $verify = true)
    {
        if ($verify && is_array($this->verify())) {
            return false;
        }
        
        $meta = $this->_em->getClassMetadata($this->_entityName);        
        $nodes = $this->children($node, true, $sortByField, $direction);
        foreach ($nodes as $node) {
            if (!isset($config)) {
                $config = $node->getTreeConfiguration();
            }
            // this is overhead but had to be refreshed
            $this->_em->refresh($node);
            $right = $meta->getReflectionProperty($config->getRightField())->getValue($node);
            $left = $meta->getReflectionProperty($config->getLeftField())->getValue($node);
            $this->moveDown($node, true);
            if ($left != ($right - 1)) {
                $this->reorder($node, $sortByField, $direction, false);
            }
        }
        return true;
    }
    
    /**
     * Removes given $node from the tree and reparents its descendants
     * 
     * @param Node $node
     * @throws Exception if something fails in transaction
     * @return void
     */
    public function removeFromTree(Node $node)
    {
        $meta = $this->_em->getClassMetadata($this->_entityName);
        $config = $node->getTreeConfiguration();
        
        $right = $meta->getReflectionProperty($config->getRightField())
            ->getValue($node);
        $left = $meta->getReflectionProperty($config->getLeftField())
            ->getValue($node);
        $parent = $meta->getReflectionProperty($config->getParentField())
            ->getValue($node);
            
        if ($right == $left + 1) {
            $this->_em->remove($node);
            $this->_em->flush();
            return;
        }
        // process updates in transaction
        $this->_em->getConnection()->beginTransaction();
        try {
            $this->_em->refresh($parent);
            $pk = $meta->getSingleIdentifierFieldName();
            $parentId = $meta->getReflectionProperty($pk)->getValue($parent);
            $nodeId = $meta->getReflectionProperty($pk)->getValue($node);
            
            $dql = "UPDATE {$this->_entityName} node";
            $dql .= ' SET node.' . $config->getParentField() . ' = ' . $parentId;
            $dql .= ' WHERE node.' . $config->getParentField() . ' = ' . $nodeId;
            $q = $this->_em->createQuery($dql);
            $q->getSingleScalarResult();
            
            $this->_sync($config, 1, '-', 'BETWEEN ' . ($left + 1) . ' AND ' . ($right - 1));
            $this->_sync($config, 2, '-', '> ' . $right);
            
            $dql = "UPDATE {$this->_entityName} node";
            $dql .= ' SET node.' . $config->getParentField() . ' = NULL,';
            $dql .= ' node.' . $config->getLeftField() . ' = 0,';
            $dql .= ' node.' . $config->getRightField() . ' = 0';
            $dql .= ' WHERE node.' . $pk . ' = ' . $nodeId;
            $q = $this->_em->createQuery($dql);
            $q->getSingleScalarResult();
            $this->_em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->_em->close();
            $this->_em->getConnection()->rollback();
            throw $e;
        }
        $this->_em->refresh($node);
        $this->_em->remove($node);
        $this->_em->flush();
    }
    
    /**
     * Verifies that current tree is valid.
     * If any error is detected it will return an array
     * with a list of errors found on tree
     * 
     * @return mixed
     *         boolean - true on success
     *         array - error list on failure
     */
    public function verify()
    {
        if (!$this->childCount()) {
            return true; // tree is empty
        }
        $errors = array();
        $meta = $this->_em->getClassMetadata($this->_entityName);
        $node = new $this->_entityName();
        $config = $node->getTreeConfiguration();
        $identifier = $meta->getSingleIdentifierFieldName();
        $leftField = $config->getLeftField();
        $rightField = $config->getRightField();
        $parentField = $config->getParentField();
        
        $q = $this->_em->createQuery("SELECT MIN(node.{$leftField}) FROM {$this->_entityName} node");
        
        $min = intval($q->getSingleScalarResult());
        $edge = $this->_getTreeEdge($config);
        for ($i = $min; $i <= $edge; $i++) {
            $dql = "SELECT COUNT(node.{$identifier}) FROM {$this->_entityName} node";
            $dql .= " WHERE (node.{$leftField} = {$i} OR node.{$rightField} = {$i})";
            $q = $this->_em->createQuery($dql);
            $count = intval($q->getSingleScalarResult());
            if ($count != 1) {
                if ($count == 0) {
                    $errors[] = "index [{$i}], missing";
                } else {
                    $errors[] = "index [{$i}], duplicate";
                }
            }
        }
        
        // check for missing parents
        $dql = "SELECT c FROM {$this->_entityName} c";
        $dql .= " LEFT JOIN c.{$parentField} p";
        $dql .= " WHERE c.{$parentField} IS NOT NULL";
        $dql .= " AND p.{$identifier} IS NULL";
        $q = $this->_em->createQuery($dql);
        $nodes = $q->getArrayResult();
        if (count($nodes)) {
            foreach ($nodes as $node) {
                $errors[] = "node [{$node[$identifier]}] has missing parent";
            }
            return $errors; // loading broken relation can cause infinite loop
        }
        
        $dql = "SELECT node FROM {$this->_entityName} node";
        $dql .= " WHERE node.{$rightField} < node.{$leftField}";
        $q = $this->_em->createQuery($dql);
        $q->setMaxResults(1);
        $result = $q->getResult(Query::HYDRATE_OBJECT);
        $node = count($result) ? array_shift($result) : null; 
        
        if ($node) {
            $id = $meta->getReflectionProperty($identifier)->getValue($node);
            $errors[] = "node [{$id}], left is greater than right";
        }
        
        foreach ($this->findAll() as $node) {
            $right = $meta->getReflectionProperty($rightField)->getValue($node);
            $left = $meta->getReflectionProperty($leftField)->getValue($node);
            $id = $meta->getReflectionProperty($identifier)->getValue($node);
            $parent = $meta->getReflectionProperty($parentField)->getValue($node);
            if (!$right || !$left) {
                $errors[] = "node [{$id}] has invalid left or right values";
            } elseif ($right == $left) {
                $errors[] = "node [{$id}] has identical left and right values";
            } elseif ($parent) {
                $this->_em->refresh($parent);
                $parentRight = $meta->getReflectionProperty($rightField)->getValue($parent);
                $parentLeft = $meta->getReflectionProperty($leftField)->getValue($parent);
                $parentId = $meta->getReflectionProperty($identifier)->getValue($parent);
                if ($left < $parentLeft) {
                    $errors[] = "node [{$id}] left is less than parent`s [{$parentId}] left value";
                } elseif ($right > $parentRight) {
                    $errors[] = "node [{$id}] right is greater than parent`s [{$parentId}] right value";
                }
            } else {
                $dql = "SELECT COUNT(node.{$identifier}) FROM {$this->_entityName} node";
                $dql .= " WHERE node.{$leftField} < {$left}";
                $dql .= " AND node.{$rightField} > {$right}";
                $q = $this->_em->createQuery($dql);
                if ($count = intval($q->getSingleScalarResult())) {
                    $errors[] = "node [{$id}] parent field is blank, but it has a parent";
                }
            }
        }
        return $errors ?: true;
    }
    
    /**
     * Tries to recover the tree
     * 
     * @throws Exception if something fails in transaction
     * @return void
     */
    public function recover()
    {
        if ($this->verify() === true) {
            return;
        }
        
        $meta = $this->_em->getClassMetadata($this->_entityName);
        $node = new $this->_entityName();
        $config = $node->getTreeConfiguration();
        
        $identifier = $meta->getSingleIdentifierFieldName();
        $leftField = $config->getLeftField();
        $rightField = $config->getRightField();
        $parentField = $config->getParentField();
        
        $count = 1;
        $dql = "SELECT node.{$identifier} FROM {$this->_entityName} node";
        $dql .= " ORDER BY node.{$leftField} ASC";
        $q = $this->_em->createQuery($dql);
        $nodes = $q->getArrayResult();
        // process updates in transaction
        $this->_em->getConnection()->beginTransaction();
        try {
            foreach ($nodes as $node) {
                $left = $count++;
                $right = $count++;
                $dql = "UPDATE {$this->_entityName} node";
                $dql .= " SET node.{$leftField} = {$left},";
                $dql .= " node.{$rightField} = {$right}";
                $dql .= " WHERE node.{$identifier} = {$node[$identifier]}";
                $q = $this->_em->createQuery($dql);
                $q->getSingleScalarResult();
            }
            foreach ($nodes as $node) {
                $node = $this->_em->getReference($this->_entityName, $node[$identifier]);
                $this->_em->refresh($node);
                $parent = $meta->getReflectionProperty($parentField)->getValue($node);
                $this->_adjustNodeWithParent($config, $parent, $node);
            }
            $this->_em->getConnection()->commit();
        } catch (\Exception $e) {
            $this->_em->close();
            $this->_em->getConnection()->rollback();
            throw $e;
        }
    }
    
    /**
     * Get the edge of tree
     *
     * @param Configuration $config
     * @return integer
     */
    protected function _getTreeEdge(Configuration $config)
    {
        $right = $config->getRightField();
        $q = $this->_em->createQuery("SELECT MAX(node.{$right}) FROM {$this->_entityName} node");
        $q->useResultCache(false);
        $q->useQueryCache(false);
        $right = $q->getSingleScalarResult();
        return intval($right);
    }
    
    /**
     * Synchronize the tree with given conditions
     * 
     * @param Configuration $config
     * @param integer $shift
     * @param string $dir
     * @param string $conditions
     * @param string $field
     * @return void
     */
    protected function _sync(Configuration $config, $shift, $dir, $conditions, $field = 'both')
    {
        if ($field == 'both') {
            $this->_sync($config, $shift, $dir, $conditions, $config->getLeftField());
            $field = $config->getRightField();
        }
        
        $dql = "UPDATE {$this->_entityName} node";
        $dql .= " SET node.{$field} = node.{$field} {$dir} {$shift}";
        $dql .= " WHERE node.{$field} {$conditions}";
        $q = $this->_em->createQuery($dql);
        return $q->getSingleScalarResult();
    }
    
    /**
     * Synchronize tree according to Node`s parent Node
     * 
     * @param Configuration $config
     * @param Node $parent
     * @param Node $node
     * @return void
     */
    protected function _adjustNodeWithParent(Configuration $config, $parent, Node $node)
    {
        $edge = $this->_getTreeEdge($config);
        $meta = $this->_em->getClassMetadata($this->_entityName);
        $leftField = $config->getLeftField();
        $rightField = $config->getRightField();
        $parentField = $config->getParentField();
        
        $leftValue = $meta->getReflectionProperty($leftField)->getValue($node);
        $rightValue = $meta->getReflectionProperty($rightField)->getValue($node);
        if ($parent === null) {
            $this->_sync($config, $edge - $leftValue + 1, '+', 'BETWEEN ' . $leftValue . ' AND ' . $rightValue);
            $this->_sync($config, $rightValue - $leftValue + 1, '-', '> ' . $leftValue);
        } else {
            // need to refresh the parent to get up to date left and right
            $this->_em->refresh($parent);
            $parentLeftValue = $meta->getReflectionProperty($leftField)->getValue($parent);
            $parentRightValue = $meta->getReflectionProperty($rightField)->getValue($parent);
            if ($leftValue < $parentLeftValue && $parentRightValue < $rightValue) {
                return;
            }
            if (empty($leftValue) && empty($rightValue)) {
                $this->_sync($config, 2, '+', '>= ' . $parentRightValue);
                // cannot schedule this update if other Nodes pending
                $qb = $this->_em->createQueryBuilder();
                $qb->update($this->_entityName, 'node')
                    ->set('node.' . $leftField, $parentRightValue)
                    ->set('node.' . $rightField, $parentRightValue + 1);
                $entityIdentifiers = $meta->getIdentifierValues($node);
                foreach ($entityIdentifiers as $field => $value) {
                    if (strlen($value)) {
                        $qb->where('node.' . $field . ' = ' . $value);
                    }
                }
                $q = $qb->getQuery();
                $q->getSingleScalarResult();
            } else {
                $this->_sync($config, $edge - $leftValue + 1, '+', 'BETWEEN ' . $leftValue . ' AND ' . $rightValue);
                $diff = $rightValue - $leftValue + 1;
                
                if ($leftValue > $parentLeftValue) {
                    if ($rightValue < $parentRightValue) {
                        $this->_sync($config, $diff, '-', 'BETWEEN ' . $rightValue . ' AND ' . ($parentRightValue - 1));
                        $this->_sync($config, $edge - $parentRightValue + $diff + 1, '-', '> ' . $edge);
                    } else {
                        $this->_sync($config, $diff, '+', 'BETWEEN ' . $parentRightValue . ' AND ' . $rightValue);
                        $this->_sync($config, $edge - $parentRightValue + 1, '-', '> ' . $edge);
                    }
                } else {
                    $this->_sync($config, $diff, '-', 'BETWEEN ' . $rightValue . ' AND ' . ($parentRightValue - 1));
                    $this->_sync($config, $edge - $parentRightValue + $diff + 1, '-', '> ' . $edge);
                }
            }
        }
    }
}
