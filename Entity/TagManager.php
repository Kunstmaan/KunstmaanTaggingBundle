<?php

namespace Kunstmaan\TaggingBundle\Entity;

use DoctrineExtensions\Taggable\TagManager as BaseTagManager;
use DoctrineExtensions\Taggable\Taggable as BaseTaggable;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\ResultSetMappingBuilder;

use Kunstmaan\NodeBundle\Entity\AbstractPage;

class TagManager extends BaseTagManager
{

    const TAGGING_HYDRATOR = 'taggingHydrator';

    public function copyTags($origin, $destination)
    {
        $tags = $this->getTagging($origin);
        $this->replaceTags($tags, $destination);
        $this->saveTagging($destination);
    }

    public function loadTagging(BaseTaggable $resource)
    {
        if ($resource instanceof LazyLoadingTaggableInterface) {
            $resource->setTagLoader(function (Taggable $taggable) {
                parent::loadTagging($taggable);
            });

            return ;
        }

        parent::loadTagging($resource);
    }

    public function saveTagging(BaseTaggable $resource)
    {
        $tags = clone $resource->getTags();
        parent::saveTagging($resource);
        if (sizeof($tags) !== sizeof($resource->getTags())) {
            // parent::saveTagging uses getTags by reference and removes elements, so it ends up empty :-/
            // this causes all tags to be deleted when an entity is persisted more than once in a request
            // Restore:
            $this->replaceTags($tags->toArray(), $resource);
        }

    }


    /**
     * @param $tag
     * @return array
     */
    public function getResourcesByTag($tag, $entityName, $type)
    {
        $em = $this->em;

        $config = $em->getConfiguration();
        if (is_null($config->getCustomHydrationMode(TagManager::TAGGING_HYDRATOR))) {
            $config->addCustomHydrationMode(TagManager::TAGGING_HYDRATOR, 'Doctrine\ORM\Internal\Hydration\ObjectHydrator');
        }

        if ($type) {
            $joinCriteria = 't2.resourceType = :type and t2.resourceId = p.id';
        }
        else {
            $joinCriteria = 't2.resourceId = p.id';
        }

        $qb = $em
            ->createQueryBuilder()
            ->select('p')
            ->from($entityName, 'p')
            ->innerJoin($this->taggingClass, 't2', Expr\Join::WITH, $joinCriteria)
            ->innerJoin('t2.tag', 't', Expr\Join::WITH, 't.name = :tag')
            ->setParameter('tag', $tag);

        if($type) {
            $qb->setParameter('type', $type);
        }

        $query = $qb->getQuery();
        $pages = $query->getResult(TagManager::TAGGING_HYDRATOR);

        return $pages;
    }
    /**
     * Gets all tags for the given taggable resource
     *
     * @param BaseTaggable $resource Taggable resource
     * @return array
     */
    public function getTagging(BaseTaggable $resource)
    {
        $em = $this->em;

        $config = $em->getConfiguration();
        if (is_null($config->getCustomHydrationMode(self::TAGGING_HYDRATOR))) {
            $config->addCustomHydrationMode(self::TAGGING_HYDRATOR, 'Doctrine\ORM\Internal\Hydration\ObjectHydrator');
        }

        $qb = $em
            ->createQueryBuilder()
            ->select('t')
            ->from($this->tagClass, 't')
            ->innerJoin('t.tagging', 't2', Expr\Join::WITH, 't2.resourceId = :id AND t2.resourceType = :type')
            ->setParameter('id', $resource->getTaggableId())
            ->setParameter('type', $resource->getTaggableType());

        $query = $qb
            ->getQuery();
        $result = $query
            ->getResult(self::TAGGING_HYDRATOR);

        return $result;
    }

    public function getTagsByResourceType($type)
    {

        $qb = $this->em->createQueryBuilder()
            ->select('t.name, COUNT(t2) as cnt')
            ->from($this->tagClass, 't')
            ->leftJoin('t.tagging', 't2', Expr\Join::WITH, 't2.resourceType = \'' . $type . '\'')
            ->groupBy('t')
            ->orderBy('cnt', 'desc')
            ->having('cnt > 0');

        $query = $qb->getQuery();
        $results = $query->execute();
        return $results;
    }


    public function findById($id)
    {

        if (!isset($id) || is_null($id)) {
            return NULL;
        }
        $builder = $this->em->createQueryBuilder();

        $tag = $builder
            ->select('t')
            ->from($this->tagClass, 't')

            ->where($builder->expr()->eq('t.id', $id))

            ->getQuery()
            ->getOneOrNullResult();

        return $tag;
    }

    public function findAll()
    {
        $tagsRepo = $this->em->getRepository($this->tagClass);

        return $tagsRepo->findAll();
    }

    public function findByName($name)
    {
        $tagsRepo = $this->em->getRepository($this->tagClass);

        return $tagsRepo->findOneBy(['name' => $name]);
    }

    public function findRelatedItems(Taggable $item, $class, $locale, $nbOfItems=1)
    {
        $instance = new $class();
        if (!($instance instanceof Taggable)) {
            return NULL;
        }

        $em = $this->em;
        $rsm = new ResultSetMappingBuilder($em);
        $rsm->addRootEntityFromClassMetadata($class, 'i');

        $meta = $em->getClassMetadata($class);
        $tableName = $meta->getTableName();

        $escapedClass = str_replace('\\', '\\\\', $class);

        $query = <<<EOD
            SELECT i.*, COUNT(i.id) as number
            FROM {$tableName} i
            LEFT JOIN kuma_taggings t
            ON t.resource_id = i.id
            AND t.resource_type = '{$instance->getTaggableType()}'
            WHERE t.tag_id IN (
                SELECT tg.tag_id
                FROM kuma_taggings tg
                WHERE tg.resource_id = {$item->getId()}
                AND tg.resource_type = '{$item->getTaggableType()}'
            )
            AND i.id <> {$item->getId()}
EOD;

        if ($item instanceof AbstractPage) {
            $query .= <<< EOD
                AND i.id IN (
                    SELECT nodeversion.refId
                    FROM kuma_nodes as node
                    INNER JOIN kuma_node_translations as nodetranslation
                    ON node.id = nodetranslation.node
                    AND nodetranslation.lang = '{$locale}'
                    INNER JOIN kuma_node_versions as nodeversion
                    ON nodetranslation.publicNodeVersion = nodeversion.id
                    AND nodeversion.refEntityname = '{$escapedClass}'
                    AND node.deleted = 0
                    AND nodetranslation.online = 1
                )
EOD;
        }

        $query .= <<<EOD
            GROUP BY
                i.id
            HAVING
                number > 0
            ORDER BY
                number DESC
            LIMIT {$nbOfItems};
EOD;

        $items = $em->createNativeQuery($query, $rsm)->getResult();

        return $items;
    }

}
