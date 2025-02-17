<?php
/**
 * ownCloud - App Framework
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @copyright Bernhard Posselt 2014
 */

namespace Test\AppFramework\Middleware\Security;

use OC\AppFramework\Http\Request;
use OC\AppFramework\Middleware\Security\CORSMiddleware;
use OC\AppFramework\Middleware\Security\Exceptions\SecurityException;
use OC\AppFramework\Utility\ControllerMethodReflector;
use OC\Security\Bruteforce\Throttler;
use OC\User\Session;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequestId;

class CORSMiddlewareTest extends \Test\TestCase {
	/** @var ControllerMethodReflector */
	private $reflector;
	/** @var Session|\PHPUnit\Framework\MockObject\MockObject */
	private $session;
	/** @var Throttler */
	private $throttler;
	/** @var Controller */
	private $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->reflector = new ControllerMethodReflector();
		$this->session = $this->createMock(Session::class);
		$this->throttler = $this->createMock(Throttler::class);
		$this->controller = $this->createMock(Controller::class);
	}

	/**
	 * @CORS
	 */
	public function testSetCORSAPIHeader() {
		$request = new Request(
			[
				'server' => [
					'HTTP_ORIGIN' => 'test'
				]
			],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$this->reflector->reflect($this, __FUNCTION__);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);

		$response = $middleware->afterController($this->controller, __FUNCTION__, new Response());
		$headers = $response->getHeaders();
		$this->assertEquals('test', $headers['Access-Control-Allow-Origin']);
	}


	public function testNoAnnotationNoCORSHEADER() {
		$request = new Request(
			[
				'server' => [
					'HTTP_ORIGIN' => 'test'
				]
			],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);

		$response = $middleware->afterController($this->controller, __FUNCTION__, new Response());
		$headers = $response->getHeaders();
		$this->assertFalse(array_key_exists('Access-Control-Allow-Origin', $headers));
	}


	/**
	 * @CORS
	 */
	public function testNoOriginHeaderNoCORSHEADER() {
		$request = new Request(
			[],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$this->reflector->reflect($this, __FUNCTION__);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);

		$response = $middleware->afterController($this->controller, __FUNCTION__, new Response());
		$headers = $response->getHeaders();
		$this->assertFalse(array_key_exists('Access-Control-Allow-Origin', $headers));
	}


	/**
	 * @CORS
	 */
	public function testCorsIgnoredIfWithCredentialsHeaderPresent() {
		$this->expectException(\OC\AppFramework\Middleware\Security\Exceptions\SecurityException::class);

		$request = new Request(
			[
				'server' => [
					'HTTP_ORIGIN' => 'test'
				]
			],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$this->reflector->reflect($this, __FUNCTION__);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);

		$response = new Response();
		$response->addHeader('AcCess-control-Allow-Credentials ', 'TRUE');
		$middleware->afterController($this->controller, __FUNCTION__, $response);
	}

	/**
	 * CORS must not be enforced for anonymous users on public pages
	 *
	 * @CORS
	 * @PublicPage
	 */
	public function testNoCORSOnAnonymousPublicPage() {
		$request = new Request(
			[],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$this->reflector->reflect($this, __FUNCTION__);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);
		$this->session->expects($this->once())
			->method('isLoggedIn')
			->willReturn(false);
		$this->session->expects($this->never())
			->method('logout');
		$this->session->expects($this->never())
			->method('logClientIn')
			->with($this->equalTo('user'), $this->equalTo('pass'))
			->willReturn(true);
		$this->reflector->reflect($this, __FUNCTION__);

		$middleware->beforeController($this->controller, __FUNCTION__);
	}

	/**
	 * Even on public pages users logged in using session cookies,
	 * that do not provide a valid CSRF token are disallowed
	 *
	 * @CORS
	 * @PublicPage
	 */
	public function testCORSShouldNeverAllowCookieAuth() {
		$request = new Request(
			[],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$this->reflector->reflect($this, __FUNCTION__);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);
		$this->session->expects($this->once())
			->method('isLoggedIn')
			->willReturn(true);
		$this->session->expects($this->once())
			->method('logout');
		$this->session->expects($this->never())
			->method('logClientIn')
			->with($this->equalTo('user'), $this->equalTo('pass'))
			->willReturn(true);

		$this->expectException(SecurityException::class);
		$middleware->beforeController($this->controller, __FUNCTION__);
	}

	/**
	 * @CORS
	 */
	public function testCORSShouldRelogin() {
		$request = new Request(
			['server' => [
				'PHP_AUTH_USER' => 'user',
				'PHP_AUTH_PW' => 'pass'
			]],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$this->session->expects($this->once())
			->method('logout');
		$this->session->expects($this->once())
			->method('logClientIn')
			->with($this->equalTo('user'), $this->equalTo('pass'))
			->willReturn(true);
		$this->reflector->reflect($this, __FUNCTION__);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);

		$middleware->beforeController($this->controller, __FUNCTION__);
	}

	/**
	 * @CORS
	 */
	public function testCORSShouldFailIfPasswordLoginIsForbidden() {
		$this->expectException(\OC\AppFramework\Middleware\Security\Exceptions\SecurityException::class);

		$request = new Request(
			['server' => [
				'PHP_AUTH_USER' => 'user',
				'PHP_AUTH_PW' => 'pass'
			]],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$this->session->expects($this->once())
			->method('logout');
		$this->session->expects($this->once())
			->method('logClientIn')
			->with($this->equalTo('user'), $this->equalTo('pass'))
			->will($this->throwException(new \OC\Authentication\Exceptions\PasswordLoginForbiddenException));
		$this->reflector->reflect($this, __FUNCTION__);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);

		$middleware->beforeController($this->controller, __FUNCTION__);
	}

	/**
	 * @CORS
	 */
	public function testCORSShouldNotAllowCookieAuth() {
		$this->expectException(\OC\AppFramework\Middleware\Security\Exceptions\SecurityException::class);

		$request = new Request(
			['server' => [
				'PHP_AUTH_USER' => 'user',
				'PHP_AUTH_PW' => 'pass'
			]],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$this->session->expects($this->once())
			->method('logout');
		$this->session->expects($this->once())
			->method('logClientIn')
			->with($this->equalTo('user'), $this->equalTo('pass'))
			->willReturn(false);
		$this->reflector->reflect($this, __FUNCTION__);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);

		$middleware->beforeController($this->controller, __FUNCTION__);
	}

	public function testAfterExceptionWithSecurityExceptionNoStatus() {
		$request = new Request(
			['server' => [
				'PHP_AUTH_USER' => 'user',
				'PHP_AUTH_PW' => 'pass'
			]],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);
		$response = $middleware->afterException($this->controller, __FUNCTION__, new SecurityException('A security exception'));

		$expected = new JSONResponse(['message' => 'A security exception'], 500);
		$this->assertEquals($expected, $response);
	}

	public function testAfterExceptionWithSecurityExceptionWithStatus() {
		$request = new Request(
			['server' => [
				'PHP_AUTH_USER' => 'user',
				'PHP_AUTH_PW' => 'pass'
			]],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);
		$response = $middleware->afterException($this->controller, __FUNCTION__, new SecurityException('A security exception', 501));

		$expected = new JSONResponse(['message' => 'A security exception'], 501);
		$this->assertEquals($expected, $response);
	}


	public function testAfterExceptionWithRegularException() {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('A regular exception');

		$request = new Request(
			['server' => [
				'PHP_AUTH_USER' => 'user',
				'PHP_AUTH_PW' => 'pass'
			]],
			$this->createMock(IRequestId::class),
			$this->createMock(IConfig::class)
		);
		$middleware = new CORSMiddleware($request, $this->reflector, $this->session, $this->throttler);
		$middleware->afterException($this->controller, __FUNCTION__, new \Exception('A regular exception'));
	}
}
