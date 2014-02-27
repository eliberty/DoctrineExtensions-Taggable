<?php

/*
 * This file is part of the Doctrine Extensions Taggable package.
 * (c) 2011 Fabien Pennequin <fabien@pennequin.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DoctrineExtensions\Taggable;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\Expr;
use Doctrine\Common\Collections\ArrayCollection;
use DoctrineExtensions\Taggable\Entity\Tag;
use DoctrineExtensions\Taggable\Entity\Tagging;

/**
 * TagManager.
 *
 * @author Fabien Pennequin <fabien@pennequin.me>
 */
class TagManager
{
    protected $em;
    protected $tagClass;
    protected $taggingClass;

    public function __construct(EntityManager $em, $tagClass = null, $taggingClass = null)
    {
        $this->em = $em;

        $this->tagClass = $tagClass ?: 'DoctrineExtensions\Taggable\Entity\Tag';
        $this->taggingClass = $taggingClass ?: 'DoctrineExtensions\Taggable\Entity\Tagging';
    }

    /**
     * Loads or creates a tag from tag name
     *
     * @param array  $name  Tag name
     * @return Tag
     */
    public function loadOrCreateTag($name)
    {
        $tags = $this->loadOrCreateTags(array($name));
        return $tags[0];
    }

    /**
     * Loads or creates multiples tags from a list of tag names
     *
     * @param array  $names   Array of tag names
     * @return ArrayCollection
     */
    public function loadOrCreateTags(array $names)
    {
        if (empty($names)) {
            return new ArrayCollection();
        }

        $names = array_unique($names);

        $builder = $this->em->createQueryBuilder();

        $tags = $builder
            ->select('t')
            ->from($this->tagClass, 't')

            ->where($builder->expr()->in('t.name', $names))

            ->getQuery()
            ->getResult()
        ;

        $loadedNames = array();
        foreach ($tags as $tag) {
            $loadedNames[] = $tag->getName();
        }

        $missingNames = array_udiff($names, $loadedNames, 'strcasecmp');
        if (sizeof($missingNames)) {
            foreach ($missingNames as $name) {
                $tag = $this->createTag($name);
                $this->em->persist($tag);

                $tags[] = $tag;
            }

            $this->em->flush($tags);
        }

        return new ArrayCollection($tags);
    }

    /**
     * Saves tags for the given taggable resource
     *
     * @param Taggable  $resource   Taggable resource
     */
    public function saveTagging(Taggable $resource, $needFlush = true)
    {
        $oldTags = $this->getTagging($resource);
        $newTags = clone $resource->getTags();
        $tagsToAdd = $newTags;

        if ($oldTags !== null and is_array($oldTags) and !empty($oldTags)) {
            $tagsToRemove = array();

            foreach ($oldTags as $oldTag) {
                if ($newTags->exists(function ($index, $newTag) use ($oldTag) {
                    return $newTag->getName() == $oldTag->getName();
                })) {
                    $tagsToAdd->removeElement($oldTag);
                } else {
                    $tagsToRemove[] = $oldTag->getId();
                }
            }

            if (sizeof($tagsToRemove)) {
                $builder = $this->em->createQueryBuilder();
                $builder
                    ->delete($this->taggingClass, 't')
                    ->where('t.tag_id')
                    ->where($builder->expr()->in('t.tag', $tagsToRemove))
                    ->andWhere('t.resourceType = :resourceType')
                    ->setParameter('resourceType', $resource->getTaggableType())
                    ->andWhere('t.resourceId = :resourceId')
                    ->setParameter('resourceId', $resource->getTaggableId())
                    ->getQuery()
                    ->getResult()
                ;
            }
        }

        $toFlush = array();
        foreach ($tagsToAdd as $tag) {
            $this->em->persist($tag);
            //$this->em->persist($this->createTagging($tag, $resource));
            $tagging = $this->createTagging($tag, $resource);
            $this->em->persist($tagging);
            $toFlush[] = $tag;
            $toFlush[] = $tagging;
        }

        if (count($tagsToAdd) && $needFlush) {
            $this->em->flush($toFlush);
        }

        return $toFlush;
    }

    /**
     * Loads all tags for the given taggable resource
     *
     * @param Taggable  $resource   Taggable resource
     */
    public function loadTagging(Taggable $resource)
    {
        $tags = $this->getTagging($resource);
        $resource->setTags(new ArrayCollection($tags));
    }

    /**
     * Gets all tags for the given taggable resource
     *
     * @param Taggable  $resource   Taggable resource
     */
    protected function getTagging(Taggable $resource)
    {
        return $this->em
            ->createQueryBuilder()

            ->select('t')
            ->from($this->tagClass, 't')

            ->innerJoin('t.tagging', 't2', Expr\Join::WITH, 't2.resourceId = :id AND t2.resourceType = :type')
            ->setParameter('id', $resource->getTaggableId())
            ->setParameter('type', $resource->getTaggableType())

            // ->orderBy('t.name', 'ASC')

            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Deletes all tagging records for the given taggable resource
     *
     * @param Taggable  $resource   Taggable resource
     */
    public function deleteTagging(Taggable $resource)
    {
        $taggingList = $this->em->createQueryBuilder()
            ->select('t')
            ->from($this->taggingClass, 't')

            ->where('t.resourceType = :type')
            ->setParameter('type', $resource->getTaggableType())

            ->andWhere('t.resourceId = :id')
            ->setParameter('id', $resource->getTaggableId())

            ->getQuery()
            ->getResult();

        foreach ($taggingList as $tagging) {
            $this->em->remove($tagging);
        }
    }

    /**
     * Splits an string into an array of valid tag names
     *
     * @param string    $names      String of tag names
     * @param string    $separator  Tag name separator
     */
    public function splitTagNames($names, $separator = ',')
    {
        $tags = explode($separator, $names);
        $tags = array_map('trim', $tags);
        $tags = array_filter($tags, function ($value) { return !empty($value); });

        return array_values($tags);
    }

    /**
     * Returns an array of tag names for the given Taggable resource.
     *
     * @param Taggable  $resource   Taggable resource
     */
    public function getTagNames(Taggable $resource)
    {
        $names = array();

        if (sizeof($resource->getTags()) > 0) {
            foreach ($resource->getTags() as $tag) {
                $names[] = $tag->getName();
            }
        }

        return $names;
    }

    /**
     * Creates a new Tag object
     *
     * @param string    $name   Tag name
     * @return Tag
     */
    protected function createTag($name)
    {
        return new $this->tagClass($name);
    }

    /**
     * Creates a new Tagging object
     *
     * @param Tag       $tag        Tag object
     * @param Taggable  $resource   Taggable resource object
     * @return Tagging
     */
    protected function createTagging(Tag $tag, Taggable $resource)
    {
        return new $this->taggingClass($tag, $resource);
    }

    /**
     * Preload tags for a set of objects.
     * It might be usefull in case you want to
     * display a long list of taggable objects with their associated tags: it
     * avoids to load tags per object, and gets all tags in a few requests.
     *
     * @param &$entities
     */
    public function preloadTags( &$entities)
    {
        $searched = array();

        foreach ($entities as $entity) {
            if (!$entity instanceof Taggable) {
                throw new \InvalidArgumentError(
                    'Entities passed to TagManager::preloadTags() must implement DoctrineExtensions\Taggable\Taggable.'
                );
            }
            $taggable_type = $entity->getTaggableType();
            if (!isset($searched[$taggable_type])) {
                $searched[$taggable_type] = array();
            }
            $searched[$taggable_type][$entity->getTaggableId()] = $entity;
            $entity->getTags()->clear();
        }

        $qb = $this->em->createQueryBuilder()
            ->select('tagging, tag')
            ->from($this->taggingClass, 'tagging')
            ->leftJoin('tagging.tag', 'tag')
            ->orderBy('tagging.resourceId');

        foreach($searched as $taggable_type => $instances)
        {
            $qb_clone = clone $qb;
            $taggings = $qb_clone
                ->where('tagging.resourceType = :type')
                ->andWhere(
                    $qb_clone->expr()->in('tagging.resourceId', array_map(function($resourceId){ return (string) $resourceId;}, (array_keys($instances))))
                )->setParameter('type', $taggable_type)
                ->getQuery()->getResult();

            foreach($taggings as $tagging)
            {
                $entity = $instances[$tagging->getResourceId()];
                $entity->addTag($tagging->getTag());
            }
        }
    }
}
