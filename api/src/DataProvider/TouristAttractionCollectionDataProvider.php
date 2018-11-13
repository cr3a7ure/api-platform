<?php

// src/App/DataProvider/TouristAttractionCollectionDataProvider.php

namespace App\DataProvider;

use App\Entity\GeoCoordinates;
use App\Entity\TouristAttraction;
use App\Entity\PostalAddress;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\Exception\ResourceClassNotSupportedException;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Persistence\ObjectManager;
//use Unirest;

final class TouristAttractionCollectionDataProvider implements CollectionDataProviderInterface
{
  protected $requestStack;
  protected $managerRegistry;
  protected $amadeusKey;
  // protected $objectManager;

  public function __construct(RequestStack $requestStack,ManagerRegistry $managerRegistry, $amadeusKey)
    {
        $this->requestStack = $requestStack;
        $this->managerRegistry = $managerRegistry;
        $this->amadeusKey = $amadeusKey;
    }

    public function getCollection(string $resourceClass, string $operationName = null)
    {
        if (TouristAttraction::class !== $resourceClass) {
            throw new ResourceClassNotSupportedException();
        }

        // Retrieve Search parameters
        $request = $this->requestStack->getCurrentRequest();
        $searchParametersObj = $request->query->all();
        $searchParametersKeys = array_keys($searchParametersObj);
        // dump($searchParametersObj);
        // dump($searchParametersKeys);
        $searchQuery = [];
        $variable = '';
        foreach ($searchParametersObj as $key => $value) {
            if(is_array($value)){
                // foreach ($value as $i => $arrayValue) {
                //     $chainPropsKey = explode("_", $key);
                //     $propertyKey = end($chainPropsKey);
                //     dump($propertyKey);
                //     $searchQuery[$propertyKey] = $value;
                //     dump($searchQuery[$propertyKey]);
                // }
            } else {
                $chainPropsKey = explode("_", $key);
                $propertyKey = end($chainPropsKey);
                $searchQuery[$propertyKey] = $value;
            }
        }
        $attr = array();
        $url = 'https://api.sandbox.amadeus.com/v1.2/points-of-interest/yapq-search-circle';
        // $url = 'https://api.sandbox.amadeus.com/v1.2/points-of-interest/yapq-search-text';
        $headers = array('Accept' => 'application/json');
        $query = array();
        //IF we have City we search by city
        if(array_key_exists('addressLocality',$searchQuery)) {
          $query['city_name'] = $searchQuery['addressLocality'];
          $url = 'https://api.sandbox.amadeus.com/v1.2/points-of-interest/yapq-search-text';
        } else {
          // $query['city_name'] = 'Athens';
          $query['radius'] = 50;
          if(array_key_exists('latitude',$searchQuery)) {
            $query['latitude'] = $searchQuery['latitude'];
          } else {
            $query['latitude'] = 37.9703;
          }
          if(array_key_exists('longitude',$searchQuery)) {
            $query['longitude'] = $searchQuery['longitude'];
          } else {
            $query['longitude'] = 23.7278;
          }
        }
        //40.640063, 22.944419 Thessaloniki
        // $quert['category'] = 'Museum';//Landmark,Church
        $query['apikey'] = $this->amadeusKey;
        $query['number_of_images'] = 1;
        $query['number_of_results'] = 20;
        if (false) {
          $em = $this->managerRegistry->getRepository('App\Entity\TouristAttraction');
          $attr = $em->findAll();
          // dump($attr);
          return $attr;
        } else {
          $response = Unirest\Request::get($url,$headers,$query);
        }
        if ($response->code !== 200) {
          $em = $this->managerRegistry->getRepository('App\Entity\TouristAttraction');
          $attr = $em->findAll();
          return $attr;
        } else {
          $data = $response->body->points_of_interest;
          foreach ($data as $key => $value) {
            $attr[$key] = new TouristAttraction();
            $attr[$key]->setId($key);
            $attr[$key]->setName($value->title);
            $attr[$key]->setDescription($value->details->short_description);
            $attr[$key]->setUrl($value->details->wiki_page_link);
            $attr[$key]->setHasMap($value->location->google_maps_link);
            $geo = new GeoCoordinates();
            $geo->setId($key);
            $geo->setLatitude($value->location->latitude);
            $geo->setLongitude($value->location->longitude);
            $attr[$key]->setGeo($geo);
            $attr[$key]->setImage($value->main_image);
         }
          return $attr;
      }
    }
}
