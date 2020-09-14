<?php

namespace Drupal\purl\Event;

use Drupal\purl\Entity\Provider;
use Drupal\purl\MatchedModifiers;
use Drupal\purl\Modifier;
use Drupal\purl\Plugin\MethodPluginManager;
use Drupal\purl\Plugin\ModifierIndex;
use Drupal\purl\Plugin\ProviderManager;
use Drupal\purl\Plugin\Purl\Method\RequestAlteringInterface;
use Drupal\purl\PurlEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class RequestSubscriber implements EventSubscriberInterface
{

  /**
   * Group purl provider id.
   */
  const GROUP_PURL_PROVIDER = 'group_purl_provider';

  /**
   * @var ModifierIndex
   */
  protected $modifierIndex;

  /**
   * @var MatchedModifiers
   */
  protected $matchedModifiers;

  public function __construct(
    ModifierIndex $modifierIndex,
    MatchedModifiers $matchedModifiers
  )
  {
    $this->modifierIndex = $modifierIndex;
    $this->matchedModifiers = $matchedModifiers;
  }

  public static function getSubscribedEvents()
  {
    return array(
      // RouterListener comes in at 32. We need to go before it.
      KernelEvents::REQUEST => array('onRequest', 50),
    );
  }

  /**
   * @return \Drupal\purl\Modifier[]
   */
  protected function getModifiers()
  {
    return $this->modifierIndex->findAll();
  }

  protected function getMethodForProvider($providerId)
  {
    return Provider::load($providerId)->getMethodPlugin();
  }

  public function onRequest(GetResponseEvent $event, $eventName, EventDispatcherInterface $dispatcher)
  {
    $request = $event->getRequest();
    $uri = $request->getPathInfo();
    [, $uri] = explode('/', $uri);

    // Eliminate the need to get and iterate through all the modifiers as it is
    // a very resource intensive process.
    $provider = Provider::load(self::GROUP_PURL_PROVIDER);
    if ($provider) {
      $provider_plugin = $this->provider->getProviderPlugin();
    }
    if ($provider_plugin) {
      $modifier = $provider_plugin->getModifierDataByKey($uri);
      // If this key is not a modifier then it means that either this is
      // any other sitewide page or this a sub request within a vsite and a
      // modifier was matched already in the previous request.
      if (empty($modifier)) {
        return;
      }
      $modifiers[] = new Modifier(key($modifier), reset($modifier), $provider->getMethodPlugin(), $provider);
    }
    // If no group purl provider is present then proceed as normal.
    else {
      $modifiers = $this->getModifiers();
    }

    $matches = array();
    foreach ($modifiers as $modifier) {

      $provider = $modifier->getProvider();
      $modifierKey = $modifier->getModifierKey();
      $method = $modifier->getMethod();

      if ($method->contains($request, $modifierKey)) {
        $matches[$provider->getProviderId()] = array(
          'method' => $method,
          'modifier' => $modifierKey,
          'provider_key' => $provider->getProviderId(),
          'provider' => $modifier->getProvider(),
          'value' => $modifier->getValue()
        );
      }
    }

    foreach ($matches as $match) {

      if ($match['method'] instanceof RequestAlteringInterface) {
        $oldrequest = $request;
        $request = $request->duplicate();
        $newrequest = $match['method']->alterRequest($request, $match['modifier']);
        if (!$newrequest) {
          $request = $oldrequest;
        }
      }
    }

    foreach ($matches as $match) {
      $modifier_event = new ModifierMatchedEvent(
        $request,
        $match['provider_key'],
        $match['method'],
        $match['modifier'],
        $match['value']
      );
      $dispatcher->dispatch(PurlEvents::MODIFIER_MATCHED, $modifier_event);
      $this->matchedModifiers->add($modifier_event);

      $request->attributes->set('purl.matched_modifiers', $matches);

      if ($match['method'] instanceof RequestAlteringInterface && $newrequest) {
        $response = $event->getKernel()
          ->handle($request, HttpKernelInterface::SUB_REQUEST);
        if ($response) {
          $event->setResponse($response);
        }
      }
    }
  }

}
