<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Tests\Controller;

use Doctrine\Common\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\ControllerTrait;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\AnonymousToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\User;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

/**
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class ControllerTraitTest extends \PHPUnit_Framework_TestCase
{
    public function testGenerateUrl()
    {
        $router = $this->getMock(RouterInterface::class);
        $router->expects($this->once())->method('generate')->willReturn('/foo');

        $controller = new TestTrait();
        $controller->setRouter($router);

        $this->assertEquals('/foo', $controller->generateUrl('foo'));
    }

    public function testForward()
    {
        $request = Request::create('/');
        $request->setLocale('fr');
        $request->setRequestFormat('xml');

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $kernel = $this->getMock(HttpKernelInterface::class);
        $kernel->expects($this->once())->method('handle')->will($this->returnCallback(function (Request $request) {
            return new Response($request->getRequestFormat().'--'.$request->getLocale());
        }));

        $controller = new TestTrait();
        $controller->setRequestStack($requestStack);
        $controller->setHttpKernel($kernel);

        $response = $controller->forward('a_controller');
        $this->assertEquals('xml--fr', $response->getContent());
    }

    public function testRedirect()
    {
        $controller = new TestTrait();
        $response = $controller->redirect('http://dunglas.fr', 301);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('http://dunglas.fr', $response->getTargetUrl());
        $this->assertSame(301, $response->getStatusCode());
    }

    public function testRedirectToRoute()
    {
        $router = $this->getMock(RouterInterface::class);
        $router->expects($this->once())->method('generate')->willReturn('/foo');

        $controller = new TestTrait();
        $controller->setRouter($router);
        $response = $controller->redirectToRoute('foo');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/foo', $response->getTargetUrl());
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testGetUser()
    {
        $user = new User('user', 'pass');
        $token = new UsernamePasswordToken($user, 'pass', 'default', array('ROLE_USER'));

        $controller = new TestTrait();
        $controller->setTokenStorage($this->getTokenStorage($token));

        $this->assertSame($controller->getUser(), $user);
    }

    public function testGetUserAnonymousUserConvertedToNull()
    {
        $token = new AnonymousToken('default', 'anon.');

        $controller = new TestTrait();
        $controller->setTokenStorage($this->getTokenStorage($token));

        $this->assertNull($controller->getUser());
    }

    public function testGetUserWithEmptyTokenStorage()
    {
        $controller = new TestTrait();
        $controller->setTokenStorage($this->getTokenStorage(null));

        $this->assertNull($controller->getUser());
    }

    /**
     * @expectedException \LogicException
     */
    public function testGetUserWithNullTokenStorage()
    {
        $controller = new TestTrait();
        $controller->getUser();
    }

    /**
     * @param TokenInterface $token
     *
     * @return TokenStorageInterface
     */
    private function getTokenStorage(TokenInterface $token = null)
    {
        $tokenStorage = $this->getMock(TokenStorageInterface::class);
        $tokenStorage
            ->expects($this->once())
            ->method('getToken')
            ->will($this->returnValue($token));

        return $tokenStorage;
    }

    public function testJson()
    {
        $controller = new TestTrait();

        $response = $controller->json(array());
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals('[]', $response->getContent());
    }

    public function testJsonWithSerializer()
    {
        $serializer = $this->getMock(SerializerInterface::class);
        $serializer
            ->expects($this->once())
            ->method('serialize')
            ->with(array(), 'json', array('json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS))
            ->will($this->returnValue('[]'));

        $controller = new TestTrait();
        $controller->setSerializer($serializer);

        $response = $controller->json(array());
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals('[]', $response->getContent());
    }

    public function testJsonWithSerializerContextOverride()
    {
        $serializer = $this->getMock(SerializerInterface::class);
        $serializer
            ->expects($this->once())
            ->method('serialize')
            ->with(array(), 'json', array('json_encode_options' => 0, 'other' => 'context'))
            ->will($this->returnValue('[]'));

        $controller = new TestTrait();
        $controller->setSerializer($serializer);

        $response = $controller->json(array(), 200, array(), array('json_encode_options' => 0, 'other' => 'context'));
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals('[]', $response->getContent());
        $response->setEncodingOptions(JSON_FORCE_OBJECT);
        $this->assertEquals('{}', $response->getContent());
    }

    public function testAddFlash()
    {
        $flashBag = new FlashBag();
        $session = $this->getMock(Session::class);
        $session->expects($this->once())->method('getFlashBag')->willReturn($flashBag);

        $controller = new TestTrait();
        $controller->setSession($session);
        $controller->addFlash('foo', 'bar');

        $this->assertSame(array('bar'), $flashBag->get('foo'));
    }

    public function testIsGranted()
    {
        $authorizationChecker = $this->getMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects($this->once())->method('isGranted')->willReturn(true);

        $controller = new TestTrait();
        $controller->setAuthorizationChecker($authorizationChecker);

        $this->assertTrue($controller->isGranted('foo'));
    }

    /**
     * @expectedException \Symfony\Component\Security\Core\Exception\AccessDeniedException
     */
    public function testDenyAccessUnlessGranted()
    {
        $authorizationChecker = $this->getMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects($this->once())->method('isGranted')->willReturn(false);

        $controller = new TestTrait();
        $controller->setAuthorizationChecker($authorizationChecker);

        $controller->denyAccessUnlessGranted('foo');
    }

    public function testRenderViewTemplating()
    {
        $templating = $this->getMock(EngineInterface::class);
        $templating->expects($this->once())->method('render')->willReturn('bar');

        $controller = new TestTrait();
        $controller->setTemplating($templating);

        $this->assertEquals('bar', $controller->renderView('foo'));
    }

    public function testRenderViewTwig()
    {
        $twig = $this->getMockBuilder(\Twig_Environment::class)->disableOriginalConstructor()->getMock();
        $twig->expects($this->once())->method('render')->willReturn('bar');

        $controller = new TestTrait();
        $controller->setTwig($twig);

        $this->assertEquals('bar', $controller->renderView('foo'));
    }

    public function testRenderTemplating()
    {
        $templating = $this->getMock(EngineInterface::class);
        $templating->expects($this->once())->method('renderResponse')->willReturn(new Response('bar'));

        $controller = new TestTrait();
        $controller->setTemplating($templating);

        $this->assertEquals('bar', $controller->render('foo')->getContent());
    }

    public function testRenderTwig()
    {
        $twig = $this->getMockBuilder(\Twig_Environment::class)->disableOriginalConstructor()->getMock();
        $twig->expects($this->once())->method('render')->willReturn('bar');

        $controller = new TestTrait();
        $controller->setTwig($twig);

        $this->assertEquals('bar', $controller->render('foo')->getContent());
    }

    public function testStreamTemplating()
    {
        $templating = $this->getMock(EngineInterface::class);

        $controller = new TestTrait();
        $controller->setTemplating($templating);

        $this->assertInstanceOf(StreamedResponse::class, $controller->stream('foo'));
    }

    public function testStreamTwig()
    {
        $twig = $this->getMockBuilder(\Twig_Environment::class)->disableOriginalConstructor()->getMock();

        $controller = new TestTrait();
        $controller->setTwig($twig);

        $this->assertInstanceOf(StreamedResponse::class, $controller->stream('foo'));
    }

    public function testCreateNotFoundException()
    {
        $controller = new TestTrait();

        $this->assertInstanceOf(NotFoundHttpException::class, $controller->createNotFoundException());
    }

    public function testCreateAccessDeniedException()
    {
        $controller = new TestTrait();

        $this->assertInstanceOf(AccessDeniedException::class, $controller->createAccessDeniedException());
    }

    public function testCreateForm()
    {
        $form = $this->getMock(FormInterface::class);

        $formFactory = $this->getMock(FormFactoryInterface::class);
        $formFactory->expects($this->once())->method('create')->willReturn($form);

        $controller = new TestTrait();
        $controller->setFormFactory($formFactory);

        $this->assertEquals($form, $controller->createForm('foo'));
    }

    public function testCreateFormBuilder()
    {
        $formBuilder = $this->getMock(FormBuilderInterface::class);

        $formFactory = $this->getMock(FormFactoryInterface::class);
        $formFactory->expects($this->once())->method('createBuilder')->willReturn($formBuilder);

        $controller = new TestTrait();
        $controller->setFormFactory($formFactory);

        $this->assertEquals($formBuilder, $controller->createFormBuilder('foo'));
    }

    public function testGetDoctrine()
    {
        $doctrine = $this->getMock(ManagerRegistry::class);

        $controller = new TestTrait();
        $controller->setDoctrine($doctrine);

        $this->assertEquals($doctrine, $controller->getDoctrine());
    }

    public function testIsCsrfTokenValid()
    {
        $tokenManager = $this->getMock(CsrfTokenManagerInterface::class);
        $tokenManager->expects($this->once())->method('isTokenValid')->willReturn(true);

        $controller = new TestTrait();
        $controller->setCsrfTokenManager($tokenManager);

        $this->assertTrue($controller->isCsrfTokenValid('foo', 'bar'));
    }
}

class TestTrait
{
    use ControllerTrait {
        forward as public;
        getUser as public;
        json as public;
        generateUrl as public;
        redirect as public;
        redirectToRoute as public;
        addFlash as public;
        isGranted as public;
        denyAccessUnlessGranted as public;
        renderView as public;
        render as public;
        stream as public;
        createNotFoundException as public;
        createAccessDeniedException as public;
        createForm as public;
        createFormBuilder as public;
        getDoctrine as public;
        isCsrfTokenValid as public;
    }
}
