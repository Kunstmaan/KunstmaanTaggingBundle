<?php

namespace Kunstmaan\TaggingBundle\EventListener;

use Doctrine\ORM\EntityManager;
use DoctrineExtensions\Taggable\Taggable;
use Kunstmaan\AdminBundle\Event\DeepCloneAndSaveEvent;
use Kunstmaan\TaggingBundle\Entity\TagManager;

/**
 * This listener will make sure the tags are copied as well
 */
class CloneListener
{

    protected $tagManager;

    public function __construct(TagManager $tagManager)
    {
        $this->tagManager = $tagManager;
    }

    /**
     * @param DeepCloneAndSaveEvent $event
     */
    public function postDeepCloneAndSave(DeepCloneAndSaveEvent $event)
    {
        $originalEntity = $event->getEntity();

        if ($originalEntity instanceof Taggable) {
            $targetEntity = $event->getClonedEntity();
            $this->tagManager->copyTags($originalEntity, $targetEntity);
        }
    }

}
