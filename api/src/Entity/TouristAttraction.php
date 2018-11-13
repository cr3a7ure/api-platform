<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * A tourist attraction.
 *
 * @see http://schema.org/TouristAttraction Documentation on Schema.org
 *
 * @ORM\Entity
 * @ApiResource(type="http://schema.org/TouristAttraction",
 *              iri="http://schema.org/TouristAttraction",
 *              attributes={"filters"={"attraction.search"},
 *                     "normalization_context"={"groups"={"readAttraction"}},
 *                     "denormalization_context"={"groups"={"writeAttraction"}}
 *             },
 *             collectionOperations={
 *                 "get"={"method"="GET", "hydra_context"={"@type"="schema:SearchAction",
 *                                                         "schema:target"="/tourist_attractions",
 *                                                         "schema:query"={"@type"="vocab:#GeoCoordinates"},
 *                                                         "schema:result"="vocab:#TouristAttraction",
 *                                                         "schema:object"="vocab:#TouristAttraction"
 *                                         }},
 *                 "post"={"method"="POST"}
 *             }
 *             )
 */
class TouristAttraction
{
    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string The name of the item
     *
     * @ORM\Column(nullable=true)
     * @Assert\Type(type="string")
     * @ApiProperty(iri="http://schema.org/name")
     * @Groups({"readAttraction", "writeAttraction"})
     */
    private $name;

    /**
     * @var string A description of the item
     *
     * @ORM\Column(nullable=true)
     * @Assert\Type(type="string")
     * @ApiProperty(iri="http://schema.org/description")
     * @Groups({"readAttraction", "writeAttraction"})
     */
    private $description;

    /**
     * @var string URL of the item
     *
     * @ORM\Column(nullable=true)
     * @Assert\Url
     * @ApiProperty(iri="http://schema.org/url")
     * @Groups({"readAttraction", "writeAttraction"})
     */
    private $url;

    /**
     * @var string A URL to a map of the place
     *
     * @ORM\Column(nullable=true)
     * @Assert\Url
     * @ApiProperty(iri="http://schema.org/hasMap")
     * @Groups({"readAttraction", "writeAttraction"})
     */
    private $hasMap;

    /**
     * @var GeoCoordinates The geo coordinates of the place
     *
     * @ORM\OneToOne(targetEntity="App\Entity\GeoCoordinates")
     * @ApiProperty(iri="http://schema.org/geo")
     * @Groups({"readAttraction", "writeAttraction"})
     */
    private $geo;

    /**
     * @var string An image of the item. This can be a \[\[URL\]\] or a fully described \[\[ImageObject\]\]
     *
     * @ORM\Column(nullable=true)
     * @Assert\Url
     * @ApiProperty(iri="http://schema.org/image")
     * @Groups({"readAttraction", "writeAttraction"})
     */
    private $image;

    /**
     * Sets id.
     *
     * @param int $id
     *
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Gets id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Sets name.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Gets name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Sets description.
     *
     * @param string $description
     *
     * @return $this
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Gets description.
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Sets url.
     *
     * @param string $url
     *
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Gets url.
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Sets hasMap.
     *
     * @param string $hasMap
     *
     * @return $this
     */
    public function setHasMap($hasMap)
    {
        $this->hasMap = $hasMap;

        return $this;
    }

    /**
     * Gets hasMap.
     *
     * @return string
     */
    public function getHasMap()
    {
        return $this->hasMap;
    }

    /**
     * Sets geo.
     *
     * @param GeoCoordinates $geo
     *
     * @return $this
     */
    public function setGeo(GeoCoordinates $geo = null)
    {
        $this->geo = $geo;

        return $this;
    }

    /**
     * Gets geo.
     *
     * @return GeoCoordinates
     */
    public function getGeo()
    {
        return $this->geo;
    }

    /**
     * Sets image.
     *
     * @param string $image
     *
     * @return $this
     */
    public function setImage($image)
    {
        $this->image = $image;

        return $this;
    }

    /**
     * Gets image.
     *
     * @return string
     */
    public function getImage()
    {
        return $this->image;
    }
}
