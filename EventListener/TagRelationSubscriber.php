<?php
/**
 * Created by PhpStorm.
 * User: hpenny
 * Date: 10/04/17
 * Time: 12:02 PM
 */

namespace Kunstmaan\TaggingBundle\EventListener;


use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Persistence\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;

class TagRelationSubscriber implements EventSubscriber
{
    protected $tagClass = 'Kunstmaan\TaggingBundle\Entity\Tag';
    protected $taggingClass = 'Kunstmaan\TaggingBundle\Entity\Tagging';

    public function __construct($tagClass, $taggingClass)
    {
        $this->tagClass = $tagClass;
        $this->taggingClass = $taggingClass;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubscribedEvents()
    {
        return array(
            Events::loadClassMetadata,
        );
    }

    /**
     * @param LoadClassMetadataEventArgs $eventArgs
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs)
    {
        // the $metadata is the whole mapping info for this class
        $metadata = $eventArgs->getClassMetadata();

        if ($metadata->getName() == $this->taggingClass) {

            $metadata->mapManyToOne(array(
                'targetEntity' => $this->tagClass,
                'fieldName' => 'tag',
                'cascade' => [],
                'inversedBy' => 'tagging',
                'JoinColumn' => array(
                    'name' => 'tag_id',
                    'unique' => false,
                    'nullable' => true,
                    'referencedColumnName' => 'id',
                    'columnDefinition' => null,
                    'onDelete' => 'CASCADE'
                ),
                'fetch' => 2,
                'type' => 2,

            ));

        }
        else if ($metadata->getName() == $this->tagClass) {

            $metadata->mapOneToMany(array(
                'targetEntity' => $this->taggingClass,
                'fieldName' => 'tagging',
                'mappedBy' => 'tag',
                'fetch' => "LAZY",
            ));

        }
    }
}