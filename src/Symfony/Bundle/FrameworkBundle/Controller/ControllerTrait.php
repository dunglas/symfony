<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Controller;

use Doctrine\Common\Persistence\ManagerRegistry;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

/**
 * Common features needed in controllers.
 *
 * Supports both autowiring trough setters and accessing services using a container.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 * @author Fabien Potencier <fabien@symfony.com>
 */
trait ControllerTrait
{
    /**
     * @var RouterInterface|null
     */
    private $router;

    /**
     * @var RequestStack|null
     */
    private $requestStack;

    /**
     * @var HttpKernelInterface|null
     */
    private $httpKernel;

    /**
     * @var SerializerInterface|null
     */
    private $serializer;

    /**
     * @var SessionInterface|null
     */
    private $session;

    /**
     * @var AuthorizationCheckerInterface|null
     */
    private $authorizationChecker;

    /**
     * @var EngineInterface|null
     */
    private $templating;

    /**
     * @var \Twig_Environment|null
     */
    private $twig;

    /**
     * @var ManagerRegistry|null
     */
    private $doctrine;

    /**
     * @var FormFactoryInterface|null
     */
    private $formFactory;

    /**
     * @var TokenStorageInterface|null
     */
    private $tokenStorage;

    /**
     * @var CsrfTokenManagerInterface|null
     */
    private $csrfTokenManager;

    /**
     * Sets the router.
     *
     * @param RouterInterface $router
     */
    public function setRouter(RouterInterface $router)
    {
        $this->router = $router;
    }

    /**
     * Sets the request stack.
     *
     * @param RequestStack $requestStack
     */
    public function setRequestStack(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    /**
     * Sets the HTTP kernel.
     *
     * @param HttpKernelInterface $httpKernel
     */
    public function setHttpKernel(HttpKernelInterface $httpKernel)
    {
        $this->httpKernel = $httpKernel;
    }

    /**
     * Sets the serializer.
     *
     * @param SerializerInterface $serializer
     */
    public function setSerializer(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * Sets the session.
     *
     * Passing the Symfony session implementation is mandatory because flashes are not part of the interface.
     *
     * @param Session $session
     */
    public function setSession(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Sets the authorization checker.
     *
     * @param AuthorizationCheckerInterface $authorizationChecker
     */
    public function setAuthorizationChecker(AuthorizationCheckerInterface $authorizationChecker)
    {
        $this->authorizationChecker = $authorizationChecker;
    }

    /**
     * Sets the templating service.
     *
     * @param EngineInterface $templating
     */
    public function setTemplating(EngineInterface $templating)
    {
        $this->templating = $templating;
    }

    /**
     * Sets Twig.
     *
     * @param \Twig_Environment $twig
     */
    public function setTwig(\Twig_Environment $twig)
    {
        $this->twig = $twig;
    }

    /**
     * Sets Doctrine.
     *
     * @param ManagerRegistry $doctrine
     */
    public function setDoctrine(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    /**
     * Sets the form factory.
     *
     * @param FormFactoryInterface $formFactory
     */
    public function setFormFactory(FormFactoryInterface $formFactory)
    {
        $this->formFactory = $formFactory;
    }

    /**
     * Sets the token storage.
     *
     * @param TokenStorageInterface $tokenStorage
     */
    public function setTokenStorage(TokenStorageInterface $tokenStorage)
    {
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * Sets the CSRF token manager.
     *
     * @param CsrfTokenManagerInterface $csrfTokenManager
     */
    public function setCsrfTokenManager(CsrfTokenManagerInterface $csrfTokenManager)
    {
        $this->csrfTokenManager = $csrfTokenManager;
    }

    private function populateTemplating()
    {
        if (null === $this->templating && isset($this->container) && $this->container->has('templating')) {
            $this->templating = $this->container->get('templating');
        }
    }

    /**
     * @param string $method
     */
    private function populateTwig($method)
    {
        if (null === $this->twig) {
            if (!isset($this->container) || !$this->container->has('twig')) {
                throw new \LogicException(sprintf('You cannot use the "%s" method if one of "setTwig" or "setTemplating" method has not been called or if none of "twig" or "templating" service is available.', $method));
            }

            $this->twig = $this->container->get('twig');
        }
    }

    private function populateFormFactory($method)
    {
        if (null === $this->formFactory) {
            if (!isset($this->container) || !$this->container->has('form.factory')) {
                throw new \LogicException(sprintf('You cannot use the "%s" method if the setRouter method has not been called or if the "form.factory" service is not available.', $method));
            }

            $this->formFactory = $this->container->get('form.factory');
        }
    }

    /**
     * Generates a URL from the given parameters.
     *
     * @param string $route         The name of the route
     * @param mixed  $parameters    An array of parameters
     * @param int    $referenceType The type of reference (one of the constants in UrlGeneratorInterface)
     *
     * @return string The generated URL
     *
     * @see UrlGeneratorInterface
     */
    protected function generateUrl($route, $parameters = array(), $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH)
    {
        if (null === $this->router) {
            if (!isset($this->container) || !$this->container->has('router')) {
                throw new \LogicException('You cannot use the "generateUrl" method if the "setRouter" method has not been called or if the "router" service is not available.');
            }

            $this->router = $this->container->get('router');
        }

        return $this->router->generate($route, $parameters, $referenceType);
    }

    /**
     * Forwards the request to another controller.
     *
     * @param string $controller The controller name (a string like BlogBundle:Post:index)
     * @param array  $path       An array of path parameters
     * @param array  $query      An array of query parameters
     *
     * @return Response A Response instance
     */
    protected function forward($controller, array $path = array(), array $query = array())
    {
        if (null === $this->requestStack) {
            if (!isset($this->container) || !$this->container->has('request_stack')) {
                throw new \LogicException('You cannot use the "forward" method if the "setRequestStack" method has not been called or if the "request_stack" service is not available.');
            }

            $this->requestStack = $this->container->get('request_stack');
        }

        if (null === $this->httpKernel) {
            if (!isset($this->container) || !$this->container->has('http_kernel')) {
                throw new \LogicException('You cannot use the "forward" method if the "setHttpKernel" method has not been called or if the "http_kernel" service is not available.');
            }

            $this->httpKernel = $this->container->get('http_kernel');
        }

        $path['_controller'] = $controller;
        $subRequest = $this->requestStack->getCurrentRequest()->duplicate($query, null, $path);

        return $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Returns a RedirectResponse to the given URL.
     *
     * @param string $url    The URL to redirect to
     * @param int    $status The status code to use for the Response
     *
     * @return RedirectResponse
     */
    protected function redirect($url, $status = 302)
    {
        return new RedirectResponse($url, $status);
    }

    /**
     * Returns a RedirectResponse to the given route with the given parameters.
     *
     * @param string $route      The name of the route
     * @param array  $parameters An array of parameters
     * @param int    $status     The status code to use for the Response
     *
     * @return RedirectResponse
     */
    protected function redirectToRoute($route, array $parameters = array(), $status = 302)
    {
        return $this->redirect($this->generateUrl($route, $parameters), $status);
    }

    /**
     * Returns a JsonResponse that uses the serializer component if enabled, or json_encode.
     *
     * @param mixed $data    The response data
     * @param int   $status  The status code to use for the Response
     * @param array $headers Array of extra headers to add
     * @param array $context Context to pass to serializer when using serializer component
     *
     * @return JsonResponse
     */
    protected function json($data, $status = 200, $headers = array(), $context = array())
    {
        if (null === $this->serializer) {
            if (isset($this->container) && $this->container->has('serializer')) {
                $this->serializer = $this->container->get('serializer');
            }
        }

        if (null === $this->serializer) {
            return new JsonResponse($data, $status, $headers);
        }

        $json = $this->serializer->serialize($data, 'json', array_merge(array(
            'json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS,
        ), $context));

        return new JsonResponse($json, $status, $headers, true);
    }

    /**
     * Adds a flash message to the current session for type.
     *
     * @param string $type    The type
     * @param string $message The message
     *
     * @throws \LogicException
     */
    protected function addFlash($type, $message)
    {
        if (null === $this->session) {
            if (!isset($this->container) || !$this->container->has('session')) {
                throw new \LogicException('You can not use the addFlash method if sessions are disabled. The setRouter method has not been called or the session service is not available.');
            }

            $this->session = $this->container->get('session');
        }

        $this->session->getFlashBag()->add($type, $message);
    }

    /**
     * Checks if the attributes are granted against the current authentication token and optionally supplied object.
     *
     * @param mixed $attributes The attributes
     * @param mixed $object     The object
     *
     * @return bool
     *
     * @throws \LogicException
     */
    protected function isGranted($attributes, $object = null)
    {
        if (null === $this->authorizationChecker) {
            if (!isset($this->container) || !$this->container->has('security.authorization_checker')) {
                throw new \LogicException('You cannot use the "isGranted" method if the "setAuthorizationChecker" method has not been called or if the "security.authorization_checker" service is not available.');
            }

            $this->authorizationChecker = $this->container->get('security.authorization_checker');
        }

        return $this->authorizationChecker->isGranted($attributes, $object);
    }

    /**
     * Throws an exception unless the attributes are granted against the current authentication token and optionally
     * supplied object.
     *
     * @param mixed  $attributes The attributes
     * @param mixed  $object     The object
     * @param string $message    The message passed to the exception
     *
     * @throws AccessDeniedException
     */
    protected function denyAccessUnlessGranted($attributes, $object = null, $message = 'Access Denied.')
    {
        if (!$this->isGranted($attributes, $object)) {
            throw $this->createAccessDeniedException($message);
        }
    }

    /**
     * Returns a rendered view.
     *
     * @param string $view       The view name
     * @param array  $parameters An array of parameters to pass to the view
     *
     * @return string The rendered view
     */
    protected function renderView($view, array $parameters = array())
    {
        $this->populateTemplating();

        if (null !== $this->templating) {
            return $this->templating->render($view, $parameters);
        }

        $this->populateTwig('renderView');

        return $this->twig->render($view, $parameters);
    }

    /**
     * Renders a view.
     *
     * @param string   $view       The view name
     * @param array    $parameters An array of parameters to pass to the view
     * @param Response $response   A response instance
     *
     * @return Response A Response instance
     */
    protected function render($view, array $parameters = array(), Response $response = null)
    {
        $this->populateTemplating();

        if (null !== $this->templating) {
            return $this->templating->renderResponse($view, $parameters, $response);
        }

        $this->populateTwig('render');

        if (null === $response) {
            $response = new Response();
        }

        return $response->setContent($this->twig->render($view, $parameters));
    }

    /**
     * Streams a view.
     *
     * @param string           $view       The view name
     * @param array            $parameters An array of parameters to pass to the view
     * @param StreamedResponse $response   A response instance
     *
     * @return StreamedResponse A StreamedResponse instance
     */
    protected function stream($view, array $parameters = array(), StreamedResponse $response = null)
    {
        $this->populateTemplating();

        if (null !== $this->templating) {
            $templating = $this->templating;

            $callback = function () use ($templating, $view, $parameters) {
                $templating->stream($view, $parameters);
            };
        } else {
            $this->populateTwig('stream');

            $twig = $this->twig;

            $callback = function () use ($twig, $view, $parameters) {
                $twig->display($view, $parameters);
            };
        }

        if (null === $response) {
            return new StreamedResponse($callback);
        }

        $response->setCallback($callback);

        return $response;
    }

    /**
     * Returns a NotFoundHttpException.
     *
     * This will result in a 404 response code. Usage example:
     *
     *     throw $this->createNotFoundException('Page not found!');
     *
     * @param string          $message  A message
     * @param \Exception|null $previous The previous exception
     *
     * @return NotFoundHttpException
     */
    protected function createNotFoundException($message = 'Not Found', \Exception $previous = null)
    {
        return new NotFoundHttpException($message, $previous);
    }

    /**
     * Returns an AccessDeniedException.
     *
     * This will result in a 403 response code. Usage example:
     *
     *     throw $this->createAccessDeniedException('Unable to access this page!');
     *
     * @param string          $message  A message
     * @param \Exception|null $previous The previous exception
     *
     * @return AccessDeniedException
     */
    protected function createAccessDeniedException($message = 'Access Denied.', \Exception $previous = null)
    {
        return new AccessDeniedException($message, $previous);
    }

    /**
     * Creates and returns a Form instance from the type of the form.
     *
     * @param string $type    The fully qualified class name of the form type
     * @param mixed  $data    The initial data for the form
     * @param array  $options Options for the form
     *
     * @return Form
     */
    protected function createForm($type, $data = null, array $options = array())
    {
        $this->populateFormFactory('createForm');

        return $this->formFactory->create($type, $data, $options);
    }

    /**
     * Creates and returns a form builder instance.
     *
     * @param mixed $data    The initial data for the form
     * @param array $options Options for the form
     *
     * @return FormBuilder
     */
    protected function createFormBuilder($data = null, array $options = array())
    {
        $this->populateFormFactory('createFormBuilder');

        return $this->formFactory->createBuilder(FormType::class, $data, $options);
    }

    /**
     * Shortcut to return the Doctrine Registry service.
     *
     * @return Registry
     *
     * @throws \LogicException If DoctrineBundle is not available
     */
    protected function getDoctrine()
    {
        if (null === $this->doctrine) {
            if (!isset($this->container) || !$this->container->has('doctrine')) {
                throw new \LogicException('You cannot use the "getDoctrine" method if the "setDoctrine" method has not been called or if the "doctrine" service is not available.');
            }

            $this->doctrine = $this->container->get('doctrine');
        }

        return $this->doctrine;
    }

    /**
     * Get a user from the Security Token Storage.
     *
     * @return mixed
     *
     * @throws \LogicException If SecurityBundle is not available
     *
     * @see TokenInterface::getUser()
     */
    protected function getUser()
    {
        if (null === $this->tokenStorage) {
            if (!isset($this->container) || !$this->container->has('security.token_storage')) {
                throw new \LogicException('You cannot use the "getUser" method if the "setTokenStorage" method has not been called or if the "security.token_storage" service is not available.');
            }

            $this->tokenStorage = $this->container->get('security.token_storage');
        }

        if (null === $token = $this->tokenStorage->getToken()) {
            return;
        }

        if (!is_object($user = $token->getUser())) {
            // e.g. anonymous authentication
            return;
        }

        return $user;
    }

    /**
     * Checks the validity of a CSRF token.
     *
     * @param string $id    The id used when generating the token
     * @param string $token The actual token sent with the request that should be validated
     *
     * @return bool
     */
    protected function isCsrfTokenValid($id, $token)
    {
        if (null === $this->csrfTokenManager) {
            if (!isset($this->container) || !$this->container->has('security.csrf.token_manager')) {
                throw new \LogicException('You cannot use the "isCsrfTokenValid" method if the "setCsrfTokenManager" method has not been called or if the "security.csrf.token_manager" service is not available.');
            }

            $this->csrfTokenManager = $this->container->get('security.csrf.token_manager');
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($id, $token));
    }
}
