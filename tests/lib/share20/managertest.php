<?php
/**
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace Test\Share20;

use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Share\IProviderFactory;
use OCP\Share\IShare;
use OC\Share20\Manager;
use OC\Share20\Exception;

use OC\Share20\Share;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IConfig;
use OCP\Share\IShareProvider;
use OCP\Security\ISecureRandom;
use OCP\Security\IHasher;
use OCP\Files\Mount\IMountManager;
use OCP\IGroupManager;

/**
 * Class ManagerTest
 *
 * @package Test\Share20
 * @group DB
 */
class ManagerTest extends \Test\TestCase {

	/** @var Manager */
	protected $manager;
	/** @var ILogger */
	protected $logger;
	/** @var IConfig */
	protected $config;
	/** @var ISecureRandom */
	protected $secureRandom;
	/** @var IHasher */
	protected $hasher;
	/** @var IShareProvider | \PHPUnit_Framework_MockObject_MockObject */
	protected $defaultProvider;
	/** @var  IMountManager */
	protected $mountManager;
	/** @var  IGroupManager */
	protected $groupManager;
	/** @var IL10N */
	protected $l;
	/** @var DummyFactory */
	protected $factory;
	/** @var IUserManager */
	protected $userManager;
	/** @var IRootFolder | \PHPUnit_Framework_MockObject_MockObject */
	protected $rootFolder;

	public function setUp() {
		
		$this->logger = $this->getMock('\OCP\ILogger');
		$this->config = $this->getMock('\OCP\IConfig');
		$this->secureRandom = $this->getMock('\OCP\Security\ISecureRandom');
		$this->hasher = $this->getMock('\OCP\Security\IHasher');
		$this->mountManager = $this->getMock('\OCP\Files\Mount\IMountManager');
		$this->groupManager = $this->getMock('\OCP\IGroupManager');
		$this->userManager = $this->getMock('\OCP\IUserManager');
		$this->rootFolder = $this->getMock('\OCP\Files\IRootFolder');

		$this->l = $this->getMock('\OCP\IL10N');
		$this->l->method('t')
			->will($this->returnCallback(function($text, $parameters = []) {
 				return vsprintf($text, $parameters);
 			}));

		$this->factory = new DummyFactory(\OC::$server);

		$this->manager = new Manager(
			$this->logger,
			$this->config,
			$this->secureRandom,
			$this->hasher,
			$this->mountManager,
			$this->groupManager,
			$this->l,
			$this->factory,
			$this->userManager,
			$this->rootFolder
		);

		$this->defaultProvider = $this->getMockBuilder('\OC\Share20\DefaultShareProvider')
			->disableOriginalConstructor()
			->getMock();
		$this->defaultProvider->method('identifier')->willReturn('default');
		$this->factory->setProvider($this->defaultProvider);


	}

	/**
	 * @return \PHPUnit_Framework_MockObject_MockBuilder
	 */
	private function createManagerMock() {
		return 	$this->getMockBuilder('\OC\Share20\Manager')
			->setConstructorArgs([
				$this->logger,
				$this->config,
				$this->secureRandom,
				$this->hasher,
				$this->mountManager,
				$this->groupManager,
				$this->l,
				$this->factory,
				$this->userManager,
				$this->rootFolder
			]);
	}

	/**
	 * @expectedException \OCP\Share\Exceptions\ShareNotFound
	 */
	public function testDeleteNoShareId() {
		$share = $this->getMock('\OCP\Share\IShare');

		$share
			->expects($this->once())
			->method('getFullId')
			->with()
			->willReturn(null);

		$this->manager->deleteShare($share);
	}

	public function dataTestDelete() {
		$user = $this->getMock('\OCP\IUser');
		$user->method('getUID')->willReturn('sharedWithUser');

		$group = $this->getMock('\OCP\IGroup');
		$group->method('getGID')->willReturn('sharedWithGroup');
	
		return [
			[\OCP\Share::SHARE_TYPE_USER, 'sharedWithUser'],
			[\OCP\Share::SHARE_TYPE_GROUP, 'sharedWithGroup'],
			[\OCP\Share::SHARE_TYPE_LINK, ''],
			[\OCP\Share::SHARE_TYPE_REMOTE, 'foo@bar.com'],
		];
	}

	/**
	 * @dataProvider dataTestDelete
	 */
	public function testDelete($shareType, $sharedWith) {
		$manager = $this->createManagerMock()
			->setMethods(['getShareById', 'deleteChildren'])
			->getMock();

		$path = $this->getMock('\OCP\Files\File');
		$path->method('getId')->willReturn(1);

		$share = $this->manager->newShare();
		$share->setId(42)
			->setProviderId('prov')
			->setShareType($shareType)
			->setSharedWith($sharedWith)
			->setSharedBy('sharedBy')
			->setNode($path)
			->setTarget('myTarget');

		$manager->expects($this->once())->method('getShareById')->with('prov:42')->willReturn($share);
		$manager->expects($this->once())->method('deleteChildren')->with($share);

		$this->defaultProvider
			->expects($this->once())
			->method('delete')
			->with($share);

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['pre', 'post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'pre_unshare', $hookListner, 'pre');
		\OCP\Util::connectHook('OCP\Share', 'post_unshare', $hookListner, 'post');

		$hookListnerExpectsPre = [
			'id' => 42,
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => $shareType,
			'shareWith' => $sharedWith,
			'itemparent' => null,
			'uidOwner' => 'sharedBy',
			'fileSource' => 1,
			'fileTarget' => 'myTarget',
		];

		$hookListnerExpectsPost = [
			'id' => 42,
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => $shareType,
			'shareWith' => $sharedWith,
			'itemparent' => null,
			'uidOwner' => 'sharedBy',
			'fileSource' => 1,
			'fileTarget' => 'myTarget',
			'deletedShares' => [
				[
					'id' => 42,
					'itemType' => 'file',
					'itemSource' => 1,
					'shareType' => $shareType,
					'shareWith' => $sharedWith,
					'itemparent' => null,
					'uidOwner' => 'sharedBy',
					'fileSource' => 1,
					'fileTarget' => 'myTarget',
				],
			],
		];


		$hookListner
			->expects($this->exactly(1))
			->method('pre')
			->with($hookListnerExpectsPre);
		$hookListner
			->expects($this->exactly(1))
			->method('post')
			->with($hookListnerExpectsPost);

		$manager->deleteShare($share);
	}

	public function testDeleteLazyShare() {
		$manager = $this->createManagerMock()
			->setMethods(['getShareById', 'deleteChildren'])
			->getMock();

		$share = $this->manager->newShare();
		$share->setId(42)
			->setProviderId('prov')
			->setShareType(\OCP\Share::SHARE_TYPE_USER)
			->setSharedWith('sharedWith')
			->setSharedBy('sharedBy')
			->setShareOwner('shareOwner')
			->setTarget('myTarget')
			->setNodeId(1)
			->setNodeType('file');

		$this->rootFolder->expects($this->never())->method($this->anything());

		$manager->expects($this->once())->method('getShareById')->with('prov:42')->willReturn($share);
		$manager->expects($this->once())->method('deleteChildren')->with($share);

		$this->defaultProvider
			->expects($this->once())
			->method('delete')
			->with($share);

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['pre', 'post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'pre_unshare', $hookListner, 'pre');
		\OCP\Util::connectHook('OCP\Share', 'post_unshare', $hookListner, 'post');

		$hookListnerExpectsPre = [
			'id' => 42,
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => \OCP\Share::SHARE_TYPE_USER,
			'shareWith' => 'sharedWith',
			'itemparent' => null,
			'uidOwner' => 'sharedBy',
			'fileSource' => 1,
			'fileTarget' => 'myTarget',
		];

		$hookListnerExpectsPost = [
			'id' => 42,
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => \OCP\Share::SHARE_TYPE_USER,
			'shareWith' => 'sharedWith',
			'itemparent' => null,
			'uidOwner' => 'sharedBy',
			'fileSource' => 1,
			'fileTarget' => 'myTarget',
			'deletedShares' => [
				[
					'id' => 42,
					'itemType' => 'file',
					'itemSource' => 1,
					'shareType' => \OCP\Share::SHARE_TYPE_USER,
					'shareWith' => 'sharedWith',
					'itemparent' => null,
					'uidOwner' => 'sharedBy',
					'fileSource' => 1,
					'fileTarget' => 'myTarget',
				],
			],
		];


		$hookListner
			->expects($this->exactly(1))
			->method('pre')
			->with($hookListnerExpectsPre);
		$hookListner
			->expects($this->exactly(1))
			->method('post')
			->with($hookListnerExpectsPost);

		$manager->deleteShare($share);
	}

	public function testDeleteNested() {
		$manager = $this->createManagerMock()
			->setMethods(['getShareById'])
			->getMock();

		$path = $this->getMock('\OCP\Files\File');
		$path->method('getId')->willReturn(1);

		$share1 = $this->manager->newShare();
		$share1->setId(42)
			->setProviderId('prov')
			->setShareType(\OCP\Share::SHARE_TYPE_USER)
			->setSharedWith('sharedWith1')
			->setSharedBy('sharedBy1')
			->setNode($path)
			->setTarget('myTarget1');

		$share2 = $this->manager->newShare();
		$share2->setId(43)
			->setProviderId('prov')
			->setShareType(\OCP\Share::SHARE_TYPE_GROUP)
			->setSharedWith('sharedWith2')
			->setSharedBy('sharedBy2')
			->setNode($path)
			->setTarget('myTarget2')
			->setParent(42);

		$share3 = $this->manager->newShare();
		$share3->setId(44)
			->setProviderId('prov')
			->setShareType(\OCP\Share::SHARE_TYPE_LINK)
			->setSharedBy('sharedBy3')
			->setNode($path)
			->setTarget('myTarget3')
			->setParent(43);

		$manager->expects($this->once())->method('getShareById')->with('prov:42')->willReturn($share1);

		$this->defaultProvider
			->method('getChildren')
			->will($this->returnValueMap([
				[$share1, [$share2]],
				[$share2, [$share3]],
				[$share3, []],
			]));

		$this->defaultProvider
			->method('delete')
			->withConsecutive($share3, $share2, $share1);

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['pre', 'post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'pre_unshare', $hookListner, 'pre');
		\OCP\Util::connectHook('OCP\Share', 'post_unshare', $hookListner, 'post');

		$hookListnerExpectsPre = [
			'id' => 42,
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => \OCP\Share::SHARE_TYPE_USER,
			'shareWith' => 'sharedWith1',
			'itemparent' => null,
			'uidOwner' => 'sharedBy1',
			'fileSource' => 1,
			'fileTarget' => 'myTarget1',
		];

		$hookListnerExpectsPost = [
			'id' => 42,
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => \OCP\Share::SHARE_TYPE_USER,
			'shareWith' => 'sharedWith1',
			'itemparent' => null,
			'uidOwner' => 'sharedBy1',
			'fileSource' => 1,
			'fileTarget' => 'myTarget1',
			'deletedShares' => [
				[
					'id' => 44,
					'itemType' => 'file',
					'itemSource' => 1,
					'shareType' => \OCP\Share::SHARE_TYPE_LINK,
					'shareWith' => '',
					'itemparent' => 43,
					'uidOwner' => 'sharedBy3',
					'fileSource' => 1,
					'fileTarget' => 'myTarget3',
				],
				[
					'id' => 43,
					'itemType' => 'file',
					'itemSource' => 1,
					'shareType' => \OCP\Share::SHARE_TYPE_GROUP,
					'shareWith' => 'sharedWith2',
					'itemparent' => 42,
					'uidOwner' => 'sharedBy2',
					'fileSource' => 1,
					'fileTarget' => 'myTarget2',
				],
				[
					'id' => 42,
					'itemType' => 'file',
					'itemSource' => 1,
					'shareType' => \OCP\Share::SHARE_TYPE_USER,
					'shareWith' => 'sharedWith1',
					'itemparent' => null,
					'uidOwner' => 'sharedBy1',
					'fileSource' => 1,
					'fileTarget' => 'myTarget1',
				],
			],
		];

		$hookListner
			->expects($this->exactly(1))
			->method('pre')
			->with($hookListnerExpectsPre);
		$hookListner
			->expects($this->exactly(1))
			->method('post')
			->with($hookListnerExpectsPost);

		$manager->deleteShare($share1);
	}

	public function testDeleteChildren() {
		$manager = $this->createManagerMock()
			->setMethods(['deleteShare'])
			->getMock();

		$share = $this->getMock('\OCP\Share\IShare');
		$share->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_USER);

		$child1 = $this->getMock('\OCP\Share\IShare');
		$child1->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_USER);
		$child2 = $this->getMock('\OCP\Share\IShare');
		$child2->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_USER);
		$child3 = $this->getMock('\OCP\Share\IShare');
		$child3->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_USER);

		$shares = [
			$child1,
			$child2,
			$child3,
		];

		$this->defaultProvider
			->expects($this->exactly(4))
			->method('getChildren')
			->will($this->returnCallback(function($_share) use ($share, $shares) {
				if ($_share === $share) {
					return $shares;
				}
				return [];
			}));

		$this->defaultProvider
			->expects($this->exactly(3))
			->method('delete')
			->withConsecutive($child1, $child2, $child3);

		$result = $this->invokePrivate($manager, 'deleteChildren', [$share]);
		$this->assertSame($shares, $result);
	}

	public function testGetShareById() {
		$share = $this->getMock('\OCP\Share\IShare');

		$this->defaultProvider
			->expects($this->once())
			->method('getShareById')
			->with(42)
			->willReturn($share);

		$this->assertEquals($share, $this->manager->getShareById('default:42'));
	}

	/**
	 * @expectedException        InvalidArgumentException
	 * @expectedExceptionMessage Passwords are enforced for link shares
	 */
	public function testVerifyPasswordNullButEnforced() {
		$this->config->method('getAppValue')->will($this->returnValueMap([
			['core', 'shareapi_enforce_links_password', 'no', 'yes'],
		]));

		$this->invokePrivate($this->manager, 'verifyPassword', [null]);
	}

	public function testVerifyPasswordNull() {
		$this->config->method('getAppValue')->will($this->returnValueMap([
				['core', 'shareapi_enforce_links_password', 'no', 'no'],
		]));

		$result = $this->invokePrivate($this->manager, 'verifyPassword', [null]);
		$this->assertNull($result);
	}

	public function testVerifyPasswordHook() {
		$this->config->method('getAppValue')->will($this->returnValueMap([
				['core', 'shareapi_enforce_links_password', 'no', 'no'],
		]));

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['listner'])->getMock();
		\OCP\Util::connectHook('\OC\Share', 'verifyPassword', $hookListner, 'listner');

		$hookListner->expects($this->once())
			->method('listner')
			->with([
				'password' => 'password',
				'accepted' => true,
				'message' => ''
			]);

		$result = $this->invokePrivate($this->manager, 'verifyPassword', ['password']);
		$this->assertNull($result);
	}

	/**
	 * @expectedException        Exception
	 * @expectedExceptionMessage password not accepted
	 */
	public function testVerifyPasswordHookFails() {
		$this->config->method('getAppValue')->will($this->returnValueMap([
				['core', 'shareapi_enforce_links_password', 'no', 'no'],
		]));

		$dummy = new DummyPassword();
		\OCP\Util::connectHook('\OC\Share', 'verifyPassword', $dummy, 'listner');
		$this->invokePrivate($this->manager, 'verifyPassword', ['password']);
	}

	public function createShare($id, $type, $path, $sharedWith, $sharedBy, $shareOwner,
		$permissions, $expireDate = null, $password = null) {
		$share = $this->getMock('\OCP\Share\IShare');

		$share->method('getShareType')->willReturn($type);
		$share->method('getSharedWith')->willReturn($sharedWith);
		$share->method('getSharedBy')->willReturn($sharedBy);
		$share->method('getSharedOwner')->willReturn($shareOwner);
		$share->method('getNode')->willReturn($path);
		$share->method('getPermissions')->willReturn($permissions);
		$share->method('getExpirationDate')->willReturn($expireDate);
		$share->method('getPassword')->willReturn($password);

		return $share;
	}

	public function dataGeneralChecks() {
		$user0 = 'user0';
		$user2 = 'user1';
		$group0 = 'group0';

		$file = $this->getMock('\OCP\Files\File');
		$node = $this->getMock('\OCP\Files\Node');

		$data = [
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $file, null, $user0, $user0, 31, null, null), 'SharedWith is not a valid user', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $file, $group0, $user0, $user0, 31, null, null), 'SharedWith is not a valid user', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $file, 'foo@bar.com', $user0, $user0, 31, null, null), 'SharedWith is not a valid user', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, $file, null, $user0, $user0, 31, null, null), 'SharedWith is not a valid group', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, $file, $user2, $user0, $user0, 31, null, null), 'SharedWith is not a valid group', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, $file, 'foo@bar.com', $user0, $user0, 31, null, null), 'SharedWith is not a valid group', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  $file, $user2, $user0, $user0, 31, null, null), 'SharedWith should be empty', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  $file, $group0, $user0, $user0, 31, null, null), 'SharedWith should be empty', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  $file, 'foo@bar.com', $user0, $user0, 31, null, null), 'SharedWith should be empty', true],
			[$this->createShare(null, -1, $file, null, $user0, $user0, 31, null, null), 'unkown share type', true],

			[$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $file, $user2, null, $user0, 31, null, null), 'SharedBy should be set', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, $file, $group0, null, $user0, 31, null, null), 'SharedBy should be set', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  $file, null, null, $user0, 31, null, null), 'SharedBy should be set', true],

			[$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $file, $user0, $user0, $user0, 31, null, null), 'Can\'t share with yourself', true],

			[$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  null, $user2, $user0, $user0, 31, null, null), 'Path should be set', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, null, $group0, $user0, $user0, 31, null, null), 'Path should be set', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  null, null, $user0, $user0, 31, null, null), 'Path should be set', true],

			[$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $node, $user2, $user0, $user0, 31, null, null), 'Path should be either a file or a folder', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, $node, $group0, $user0, $user0, 31, null, null), 'Path should be either a file or a folder', true],
			[$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  $node, null, $user0, $user0, 31, null, null), 'Path should be either a file or a folder', true],
		];

		$nonShareAble = $this->getMock('\OCP\Files\Folder');
		$nonShareAble->method('isShareable')->willReturn(false);
		$nonShareAble->method('getPath')->willReturn('path');

		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $nonShareAble, $user2, $user0, $user0, 31, null, null), 'You are not allowed to share path', true];
		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, $nonShareAble, $group0, $user0, $user0, 31, null, null), 'You are not allowed to share path', true];
		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  $nonShareAble, null, $user0, $user0, 31, null, null), 'You are not allowed to share path', true];

		$limitedPermssions = $this->getMock('\OCP\Files\File');
		$limitedPermssions->method('isShareable')->willReturn(true);
		$limitedPermssions->method('getPermissions')->willReturn(\OCP\Constants::PERMISSION_READ);
		$limitedPermssions->method('getPath')->willReturn('path');

		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $limitedPermssions, $user2, $user0, $user0, null, null, null), 'A share requires permissions', true];
		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, $limitedPermssions, $group0, $user0, $user0, null, null, null), 'A share requires permissions', true];
		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  $limitedPermssions, null, $user0, $user0, null, null, null), 'A share requires permissions', true];

		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $limitedPermssions, $user2, $user0, $user0, 31, null, null), 'Cannot increase permissions of path', true];
		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, $limitedPermssions, $group0, $user0, $user0, 17, null, null), 'Cannot increase permissions of path', true];
		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  $limitedPermssions, null, $user0, $user0, 3, null, null), 'Cannot increase permissions of path', true];

		$allPermssions = $this->getMock('\OCP\Files\Folder');
		$allPermssions->method('isShareable')->willReturn(true);
		$allPermssions->method('getPermissions')->willReturn(\OCP\Constants::PERMISSION_ALL);

		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $allPermssions, $user2, $user0, $user0, 30, null, null), 'Shares need at least read permissions', true];
		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, $allPermssions, $group0, $user0, $user0, 2, null, null), 'Shares need at least read permissions', true];
		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  $allPermssions, null, $user0, $user0, 16, null, null), 'Shares need at least read permissions', true];

		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_USER,  $allPermssions, $user2, $user0, $user0, 31, null, null), null, false];
		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_GROUP, $allPermssions, $group0, $user0, $user0, 3, null, null), null, false];
		$data[] = [$this->createShare(null, \OCP\Share::SHARE_TYPE_LINK,  $allPermssions, null, $user0, $user0, 17, null, null), null, false];

		return $data;
	}

	/**
	 * @dataProvider dataGeneralChecks
	 *
	 * @param $share
	 * @param $exceptionMessage
	 */
	public function testGeneralChecks($share, $exceptionMessage, $exception) {
		$thrown = null;

		$this->userManager->method('userExists')->will($this->returnValueMap([
			['user0', true],
			['user1', true],
		]));

		$this->groupManager->method('groupExists')->will($this->returnValueMap([
			['group0', true],
		]));

		try {
			$this->invokePrivate($this->manager, 'generalCreateChecks', [$share]);
			$thrown = false;
		} catch (\OCP\Share\Exceptions\GenericShareException $e) {
			$this->assertEquals($exceptionMessage, $e->getHint());
			$thrown = true;
		} catch(\InvalidArgumentException $e) {
			$this->assertEquals($exceptionMessage, $e->getMessage());
			$thrown = true;
		}

		$this->assertSame($exception, $thrown);
	}

	/**
	 * @expectedException \OCP\Share\Exceptions\GenericShareException
	 * @expectedExceptionMessage Expiration date is in the past
	 */
	public function testvalidateExpirationDateInPast() {

		// Expire date in the past
		$past = new \DateTime();
		$past->sub(new \DateInterval('P1D'));

		$share = $this->manager->newShare();
		$share->setExpirationDate($past);

		$this->invokePrivate($this->manager, 'validateExpirationDate', [$share]);
	}

	/**
	 * @expectedException InvalidArgumentException
	 * @expectedExceptionMessage Expiration date is enforced
	 */
	public function testvalidateExpirationDateEnforceButNotSet() {
		$share = $this->manager->newShare();

		$this->config->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_enforce_expire_date', 'no', 'yes'],
			]));

		$this->invokePrivate($this->manager, 'validateExpirationDate', [$share]);
	}

	public function testvalidateExpirationDateEnforceToFarIntoFuture() {
		// Expire date in the past
		$future = new \DateTime();
		$future->add(new \DateInterval('P7D'));

		$share = $this->manager->newShare();
		$share->setExpirationDate($future);

		$this->config->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_enforce_expire_date', 'no', 'yes'],
				['core', 'shareapi_expire_after_n_days', '7', '3'],
			]));

		try {
			$this->invokePrivate($this->manager, 'validateExpirationDate', [$share]);
		} catch (\OCP\Share\Exceptions\GenericShareException $e) {
			$this->assertEquals('Cannot set expiration date more than 3 days in the future', $e->getMessage());
			$this->assertEquals('Cannot set expiration date more than 3 days in the future', $e->getHint());
			$this->assertEquals(404, $e->getCode());
		}
	}

	public function testvalidateExpirationDateEnforceValid() {
		// Expire date in the past
		$future = new \DateTime();
		$future->add(new \DateInterval('P2D'));
		$future->setTime(0,0,0);

		$expected = clone $future;
		$future->setTime(1,2,3);

		$share = $this->manager->newShare();
		$share->setExpirationDate($future);

		$this->config->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_enforce_expire_date', 'no', 'yes'],
				['core', 'shareapi_expire_after_n_days', '7', '3'],
			]));

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['listener'])->getMock();
		\OCP\Util::connectHook('\OC\Share', 'verifyExpirationDate',  $hookListner, 'listener');
		$hookListner->expects($this->once())->method('listener')->with($this->callback(function ($data) use ($future) {
			return $data['expirationDate'] == $future;
		}));

		$future = $this->invokePrivate($this->manager, 'validateExpirationDate', [$share]);

		$this->assertEquals($expected, $future);
	}

	public function testvalidateExpirationDateNoDateNoDefaultNull() {
		$date = new \DateTime();
		$date->add(new \DateInterval('P5D'));

		$expected = clone $date;
		$expected->setTime(0,0,0);

		$share = $this->manager->newShare();
		$share->setExpirationDate($date);

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['listener'])->getMock();
		\OCP\Util::connectHook('\OC\Share', 'verifyExpirationDate',  $hookListner, 'listener');
		$hookListner->expects($this->once())->method('listener')->with($this->callback(function ($data) use ($expected) {
			return $data['expirationDate'] == $expected;
		}));

		$res = $this->invokePrivate($this->manager, 'validateExpirationDate', [$share]);

		$this->assertEquals($expected, $res);
	}

	public function testvalidateExpirationDateNoDateNoDefault() {
		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['listener'])->getMock();
		\OCP\Util::connectHook('\OC\Share', 'verifyExpirationDate',  $hookListner, 'listener');
		$hookListner->expects($this->once())->method('listener')->with($this->callback(function ($data) {
			return $data['expirationDate'] === null;
		}));

		$share = $this->manager->newShare();

		$date = $this->invokePrivate($this->manager, 'validateExpirationDate', [$share]);

		$this->assertNull($date);
	}

	public function testvalidateExpirationDateNoDateDefault() {
		$future = new \DateTime();
		$future->add(new \DateInterval('P3D'));
		$future->setTime(0,0,0);

		$expected = clone $future;

		$share = $this->manager->newShare();
		$share->setExpirationDate($future);

		$this->config->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_default_expire_date', 'no', 'yes'],
				['core', 'shareapi_expire_after_n_days', '7', '3'],
			]));

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['listener'])->getMock();
		\OCP\Util::connectHook('\OC\Share', 'verifyExpirationDate',  $hookListner, 'listener');
		$hookListner->expects($this->once())->method('listener')->with($this->callback(function ($data) use ($expected) {
			return $data['expirationDate'] == $expected;
		}));

		$this->invokePrivate($this->manager, 'validateExpirationDate', [$share]);

		$this->assertEquals($expected, $share->getExpirationDate());
	}

	public function testValidateExpirationDateHookModification() {
		$nextWeek = new \DateTime();
		$nextWeek->add(new \DateInterval('P7D'));
		$nextWeek->setTime(0,0,0);

		$save = clone $nextWeek;

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['listener'])->getMock();
		\OCP\Util::connectHook('\OC\Share', 'verifyExpirationDate',  $hookListner, 'listener');
		$hookListner->expects($this->once())->method('listener')->will($this->returnCallback(function ($data) {
			$data['expirationDate']->sub(new \DateInterval('P2D'));
		}));

		$share = $this->manager->newShare();
		$share->setExpirationDate($nextWeek);

		$this->invokePrivate($this->manager, 'validateExpirationDate', [$share]);

		$save->sub(new \DateInterval('P2D'));
		$this->assertEquals($save, $share->getExpirationDate());
	}

	/**
	 * @expectedException \Exception
	 * @expectedExceptionMessage Invalid date!
	 */
	public function testValidateExpirationDateHookException() {
		$nextWeek = new \DateTime();
		$nextWeek->add(new \DateInterval('P7D'));
		$nextWeek->setTime(0,0,0);

		$share = $this->manager->newShare();
		$share->setExpirationDate($nextWeek);

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['listener'])->getMock();
		\OCP\Util::connectHook('\OC\Share', 'verifyExpirationDate',  $hookListner, 'listener');
		$hookListner->expects($this->once())->method('listener')->will($this->returnCallback(function ($data) {
			$data['accepted'] = false;
			$data['message'] = 'Invalid date!';
		}));

		$this->invokePrivate($this->manager, 'validateExpirationDate', [$share]);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage Only sharing with group members is allowed
	 */
	public function testUserCreateChecksShareWithGroupMembersOnlyDifferentGroups() {
		$share = $this->manager->newShare();

		$sharedBy = $this->getMock('\OCP\IUser');
		$sharedWith = $this->getMock('\OCP\IUser');
		$share->setSharedBy('sharedBy')->setSharedWith('sharedWith');

		$this->groupManager
			->method('getUserGroupIds')
			->will(
				$this->returnValueMap([
					[$sharedBy, ['group1']],
					[$sharedWith, ['group2']],
				])
			);

		$this->userManager->method('get')->will($this->returnValueMap([
			['sharedBy', $sharedBy],
			['sharedWith', $sharedWith],
		]));

		$this->config
			->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_only_share_with_group_members', 'no', 'yes'],
			]));

		$this->invokePrivate($this->manager, 'userCreateChecks', [$share]);
	}

	public function testUserCreateChecksShareWithGroupMembersOnlySharedGroup() {
		$share = $this->manager->newShare();

		$sharedBy = $this->getMock('\OCP\IUser');
		$sharedWith = $this->getMock('\OCP\IUser');
		$share->setSharedBy('sharedBy')->setSharedWith('sharedWith');

		$path = $this->getMock('\OCP\Files\Node');
		$share->setNode($path);

		$this->groupManager
			->method('getUserGroupIds')
			->will(
				$this->returnValueMap([
					[$sharedBy, ['group1', 'group3']],
					[$sharedWith, ['group2', 'group3']],
				])
			);

		$this->userManager->method('get')->will($this->returnValueMap([
			['sharedBy', $sharedBy],
			['sharedWith', $sharedWith],
		]));

		$this->config
			->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_only_share_with_group_members', 'no', 'yes'],
			]));

		$this->defaultProvider
			->method('getSharesByPath')
			->with($path)
			->willReturn([]);

		$this->invokePrivate($this->manager, 'userCreateChecks', [$share]);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage  Path already shared with this user
	 */
	public function testUserCreateChecksIdenticalShareExists() {
		$share  = $this->manager->newShare();
		$share2 = $this->manager->newShare();

		$sharedWith = $this->getMock('\OCP\IUser');
		$path = $this->getMock('\OCP\Files\Node');

		$share->setSharedWith('sharedWith')->setNode($path)
			->setProviderId('foo')->setId('bar');

		$share2->setSharedWith('sharedWith')->setNode($path)
			->setProviderId('foo')->setId('baz');

		$this->defaultProvider
			->method('getSharesByPath')
			->with($path)
			->willReturn([$share2]);

		$this->invokePrivate($this->manager, 'userCreateChecks', [$share]);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage  Path already shared with this user
	 */
 	public function testUserCreateChecksIdenticalPathSharedViaGroup() {
		$share  = $this->manager->newShare();

		$sharedWith = $this->getMock('\OCP\IUser');
		$sharedWith->method('getUID')->willReturn('sharedWith');

		$this->userManager->method('get')->with('sharedWith')->willReturn($sharedWith);

		$path = $this->getMock('\OCP\Files\Node');

		$share->setSharedWith('sharedWith')
			->setNode($path)
			->setShareOwner('shareOwner')
			->setProviderId('foo')
			->setId('bar');

		$share2 = $this->manager->newShare();
		$share2->setShareType(\OCP\Share::SHARE_TYPE_GROUP)
			->setShareOwner('shareOwner2')
			->setProviderId('foo')
			->setId('baz')
			->setSharedWith('group');

		$group = $this->getMock('\OCP\IGroup');
		$group->method('inGroup')
			->with($sharedWith)
			->willReturn(true);

		$this->groupManager->method('get')->with('group')->willReturn($group);

		$this->defaultProvider
			->method('getSharesByPath')
			->with($path)
			->willReturn([$share2]);

		$this->invokePrivate($this->manager, 'userCreateChecks', [$share]);
	}

	public function testUserCreateChecksIdenticalPathNotSharedWithUser() {
		$share = $this->manager->newShare();
		$sharedWith = $this->getMock('\OCP\IUser');
		$path = $this->getMock('\OCP\Files\Node');
		$share->setSharedWith('sharedWith')
			->setNode($path)
			->setShareOwner('shareOwner')
			->setProviderId('foo')
			->setId('bar');

		$this->userManager->method('get')->with('sharedWith')->willReturn($sharedWith);

		$share2 = $this->manager->newShare();
		$share2->setShareType(\OCP\Share::SHARE_TYPE_GROUP)
			->setShareOwner('shareOwner2')
			->setProviderId('foo')
			->setId('baz');

		$group = $this->getMock('\OCP\IGroup');
		$group->method('inGroup')
			->with($sharedWith)
			->willReturn(false);

		$this->groupManager->method('get')->with('group')->willReturn($group);

		$share2->setSharedWith('group');

		$this->defaultProvider
			->method('getSharesByPath')
			->with($path)
			->willReturn([$share2]);

		$this->invokePrivate($this->manager, 'userCreateChecks', [$share]);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage Only sharing within your own groups is allowed
	 */
	public function testGroupCreateChecksShareWithGroupMembersOnlyNotInGroup() {
		$share = $this->manager->newShare();

		$user = $this->getMock('\OCP\IUser');
		$group = $this->getMock('\OCP\IGroup');
		$share->setSharedBy('user')->setSharedWith('group');

		$group->method('inGroup')->with($user)->willReturn(false);

		$this->groupManager->method('get')->with('group')->willReturn($group);
		$this->userManager->method('get')->with('user')->willReturn($user);

		$this->config
			->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_only_share_with_group_members', 'no', 'yes'],
			]));

		$this->invokePrivate($this->manager, 'groupCreateChecks', [$share]);
	}

	public function testGroupCreateChecksShareWithGroupMembersOnlyInGroup() {
		$share = $this->manager->newShare();

		$user = $this->getMock('\OCP\IUser');
		$group = $this->getMock('\OCP\IGroup');
		$share->setSharedBy('user')->setSharedWith('group');

		$this->userManager->method('get')->with('user')->willReturn($user);
		$this->groupManager->method('get')->with('group')->willReturn($group);

		$group->method('inGroup')->with($user)->willReturn(true);

		$path = $this->getMock('\OCP\Files\Node');
		$share->setNode($path);

		$this->defaultProvider->method('getSharesByPath')
			->with($path)
			->willReturn([]);

		$this->config
			->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_only_share_with_group_members', 'no', 'yes'],
			]));

		$this->invokePrivate($this->manager, 'groupCreateChecks', [$share]);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage Path already shared with this group
	 */
	public function testGroupCreateChecksPathAlreadySharedWithSameGroup() {
		$share = $this->manager->newShare();

		$path = $this->getMock('\OCP\Files\Node');
		$share->setSharedWith('sharedWith')
			->setNode($path)
			->setProviderId('foo')
			->setId('bar');

		$share2 = $this->manager->newShare();
		$share2->setSharedWith('sharedWith')
			->setProviderId('foo')
			->setId('baz');

		$this->defaultProvider->method('getSharesByPath')
			->with($path)
			->willReturn([$share2]);

		$this->invokePrivate($this->manager, 'groupCreateChecks', [$share]);
	}

	public function testGroupCreateChecksPathAlreadySharedWithDifferentGroup() {
		$share = $this->manager->newShare();

		$share->setSharedWith('sharedWith');

		$path = $this->getMock('\OCP\Files\Node');
		$share->setNode($path);

		$share2 = $this->manager->newShare();
		$share2->setSharedWith('sharedWith2');

		$this->defaultProvider->method('getSharesByPath')
			->with($path)
			->willReturn([$share2]);

		$this->invokePrivate($this->manager, 'groupCreateChecks', [$share]);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage Link sharing not allowed
	 */
	public function testLinkCreateChecksNoLinkSharesAllowed() {
		$share = $this->manager->newShare();

		$this->config
			->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_allow_links', 'yes', 'no'],
			]));

		$this->invokePrivate($this->manager, 'linkCreateChecks', [$share]);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage Link shares can't have reshare permissions
	 */
	public function testLinkCreateChecksSharePermissions() {
		$share = $this->manager->newShare();

		$share->setPermissions(\OCP\Constants::PERMISSION_SHARE);

		$this->config
			->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_allow_links', 'yes', 'yes'],
			]));

		$this->invokePrivate($this->manager, 'linkCreateChecks', [$share]);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage Link shares can't have delete permissions
	 */
	public function testLinkCreateChecksDeletePermissions() {
		$share = $this->manager->newShare();

		$share->setPermissions(\OCP\Constants::PERMISSION_DELETE);

		$this->config
			->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_allow_links', 'yes', 'yes'],
			]));

		$this->invokePrivate($this->manager, 'linkCreateChecks', [$share]);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage Public upload not allowed
	 */
	public function testLinkCreateChecksNoPublicUpload() {
		$share = $this->manager->newShare();

		$share->setPermissions(\OCP\Constants::PERMISSION_CREATE | \OCP\Constants::PERMISSION_UPDATE);

		$this->config
			->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_allow_links', 'yes', 'yes'],
				['core', 'shareapi_allow_public_upload', 'yes', 'no']
			]));

		$this->invokePrivate($this->manager, 'linkCreateChecks', [$share]);
	}

	public function testLinkCreateChecksPublicUpload() {
		$share = $this->manager->newShare();

		$share->setPermissions(\OCP\Constants::PERMISSION_CREATE | \OCP\Constants::PERMISSION_UPDATE);

		$this->config
			->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_allow_links', 'yes', 'yes'],
				['core', 'shareapi_allow_public_upload', 'yes', 'yes']
			]));

		$this->invokePrivate($this->manager, 'linkCreateChecks', [$share]);
	}

	public function testLinkCreateChecksReadOnly() {
		$share = $this->manager->newShare();

		$share->setPermissions(\OCP\Constants::PERMISSION_READ);

		$this->config
			->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_allow_links', 'yes', 'yes'],
				['core', 'shareapi_allow_public_upload', 'yes', 'no']
			]));

		$this->invokePrivate($this->manager, 'linkCreateChecks', [$share]);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Path contains files shared with you
	 */
	public function testPathCreateChecksContainsSharedMount() {
		$path = $this->getMock('\OCP\Files\Folder');
		$path->method('getPath')->willReturn('path');

		$mount = $this->getMock('\OCP\Files\Mount\IMountPoint');
		$storage = $this->getMock('\OCP\Files\Storage');
		$mount->method('getStorage')->willReturn($storage);
		$storage->method('instanceOfStorage')->with('\OCA\Files_Sharing\ISharedStorage')->willReturn(true);

		$this->mountManager->method('findIn')->with('path')->willReturn([$mount]);

		$this->invokePrivate($this->manager, 'pathCreateChecks', [$path]);
	}

	public function testPathCreateChecksContainsNoSharedMount() {
		$path = $this->getMock('\OCP\Files\Folder');
		$path->method('getPath')->willReturn('path');

		$mount = $this->getMock('\OCP\Files\Mount\IMountPoint');
		$storage = $this->getMock('\OCP\Files\Storage');
		$mount->method('getStorage')->willReturn($storage);
		$storage->method('instanceOfStorage')->with('\OCA\Files_Sharing\ISharedStorage')->willReturn(false);

		$this->mountManager->method('findIn')->with('path')->willReturn([$mount]);

		$this->invokePrivate($this->manager, 'pathCreateChecks', [$path]);
	}

	public function testPathCreateChecksContainsNoFolder() {
		$path = $this->getMock('\OCP\Files\File');

		$this->invokePrivate($this->manager, 'pathCreateChecks', [$path]);
	}

	public function dataIsSharingDisabledForUser() {
		$data = [];

		// No exclude groups
		$data[] = ['no', null, null, null, false];

		// empty exclude list, user no groups
		$data[] = ['yes', '', json_encode(['']), [], false];

		// empty exclude list, user groups
		$data[] = ['yes', '', json_encode(['']), ['group1', 'group2'], false];

		// Convert old list to json
		$data[] = ['yes', 'group1,group2', json_encode(['group1', 'group2']), [], false];

		// Old list partly groups in common
		$data[] = ['yes', 'group1,group2', json_encode(['group1', 'group2']), ['group1', 'group3'], false];

		// Old list only groups in common
		$data[] = ['yes', 'group1,group2', json_encode(['group1', 'group2']), ['group1'], true];

		// New list partly in common
		$data[] = ['yes', json_encode(['group1', 'group2']), null, ['group1', 'group3'], false];

		// New list only groups in common
		$data[] = ['yes', json_encode(['group1', 'group2']), null, ['group2'], true];

		return $data;
	}

	/**
	 * @dataProvider dataIsSharingDisabledForUser
	 *
	 * @param string $excludeGroups
	 * @param string $groupList
	 * @param string $setList
	 * @param string[] $groupIds
	 * @param bool $expected
	 */
	public function testIsSharingDisabledForUser($excludeGroups, $groupList, $setList, $groupIds, $expected) {
		$user = $this->getMock('\OCP\IUser');

		$this->config->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_exclude_groups', 'no', $excludeGroups],
				['core', 'shareapi_exclude_groups_list', '', $groupList],
			]));

		if ($setList !== null) {
			$this->config->expects($this->once())
				->method('setAppValue')
				->with('core', 'shareapi_exclude_groups_list', $setList);
		} else {
			$this->config->expects($this->never())
				->method('setAppValue');
		}

		$this->groupManager->method('getUserGroupIds')
			->with($user)
			->willReturn($groupIds);

		$this->userManager->method('get')->with('user')->willReturn($user);

		$res = $this->manager->sharingDisabledForUser('user');
		$this->assertEquals($expected, $res);
	}

	public function dataCanShare() {
		$data = [];

		/*
		 * [expected, sharing enabled, disabled for user]
		 */

		$data[] = [false, 'no', false];
		$data[] = [false, 'no', true];
		$data[] = [true, 'yes', false];
		$data[] = [false, 'yes', true];

		return $data;
	}

	/**
	 * @dataProvider dataCanShare
	 *
	 * @param bool $expected
	 * @param string $sharingEnabled
	 * @param bool $disabledForUser
	 */
	public function testCanShare($expected, $sharingEnabled, $disabledForUser) {
		$this->config->method('getAppValue')
			->will($this->returnValueMap([
				['core', 'shareapi_enabled', 'yes', $sharingEnabled],
			]));

		$manager = $this->createManagerMock()
			->setMethods(['sharingDisabledForUser'])
			->getMock();

		$manager->method('sharingDisabledForUser')->willReturn($disabledForUser);

		$user = $this->getMock('\OCP\IUser');
		$share = $this->manager->newShare();
		$share->setSharedBy('user');

		$res = $this->invokePrivate($manager, 'canShare', [$share]);
		$this->assertEquals($expected, $res);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage The Share API is disabled
	 */
	public function testCreateShareCantShare() {
		$manager = $this->createManagerMock()
			->setMethods(['canShare'])
			->getMock();

		$manager->expects($this->once())->method('canShare')->willReturn(false);
		$share = $this->manager->newShare();
		$manager->createShare($share);
	}

	public function testCreateShareUser() {
		$manager = $this->createManagerMock()
			->setMethods(['canShare', 'generalCreateChecks', 'userCreateChecks', 'pathCreateChecks'])
			->getMock();

		$shareOwner = $this->getMock('\OCP\IUser');
		$shareOwner->method('getUID')->willReturn('shareOwner');

		$path = $this->getMock('\OCP\Files\File');
		$path->method('getOwner')->willReturn($shareOwner);
		$path->method('getName')->willReturn('target');

		$share = $this->createShare(
			null,
			\OCP\Share::SHARE_TYPE_USER,
			$path,
			'sharedWith',
			'sharedBy',
			null,
			\OCP\Constants::PERMISSION_ALL);

		$manager->expects($this->once())
			->method('canShare')
			->with($share)
			->willReturn(true);
		$manager->expects($this->once())
			->method('generalCreateChecks')
			->with($share);;
		$manager->expects($this->once())
			->method('userCreateChecks')
			->with($share);;
		$manager->expects($this->once())
			->method('pathCreateChecks')
			->with($path);

		$this->defaultProvider
			->expects($this->once())
			->method('create')
			->with($share)
			->will($this->returnArgument(0));

		$share->expects($this->once())
			->method('setShareOwner')
			->with('shareOwner');
		$share->expects($this->once())
			->method('setTarget')
			->with('/target');

		$manager->createShare($share);
	}

	public function testCreateShareGroup() {
		$manager = $this->createManagerMock()
			->setMethods(['canShare', 'generalCreateChecks', 'groupCreateChecks', 'pathCreateChecks'])
			->getMock();

		$shareOwner = $this->getMock('\OCP\IUser');
		$shareOwner->method('getUID')->willReturn('shareOwner');

		$path = $this->getMock('\OCP\Files\File');
		$path->method('getOwner')->willReturn($shareOwner);
		$path->method('getName')->willReturn('target');

		$share = $this->createShare(
			null,
			\OCP\Share::SHARE_TYPE_GROUP,
			$path,
			'sharedWith',
			'sharedBy',
			null,
			\OCP\Constants::PERMISSION_ALL);

		$manager->expects($this->once())
			->method('canShare')
			->with($share)
			->willReturn(true);
		$manager->expects($this->once())
			->method('generalCreateChecks')
			->with($share);;
		$manager->expects($this->once())
			->method('groupCreateChecks')
			->with($share);;
		$manager->expects($this->once())
			->method('pathCreateChecks')
			->with($path);

		$this->defaultProvider
			->expects($this->once())
			->method('create')
			->with($share)
			->will($this->returnArgument(0));

		$share->expects($this->once())
			->method('setShareOwner')
			->with('shareOwner');
		$share->expects($this->once())
			->method('setTarget')
			->with('/target');

		$manager->createShare($share);
	}

	public function testCreateShareLink() {
		$manager = $this->createManagerMock()
			->setMethods([
				'canShare',
				'generalCreateChecks',
				'linkCreateChecks',
				'pathCreateChecks',
				'validateExpirationDate',
				'verifyPassword',
			])
			->getMock();

		$shareOwner = $this->getMock('\OCP\IUser');
		$shareOwner->method('getUID')->willReturn('shareOwner');

		$path = $this->getMock('\OCP\Files\File');
		$path->method('getOwner')->willReturn($shareOwner);
		$path->method('getName')->willReturn('target');
		$path->method('getId')->willReturn(1);

		$date = new \DateTime();

		$share = $this->manager->newShare();
		$share->setShareType(\OCP\Share::SHARE_TYPE_LINK)
			->setNode($path)
			->setSharedBy('sharedBy')
			->setPermissions(\OCP\Constants::PERMISSION_ALL)
			->setExpirationDate($date)
			->setPassword('password');

		$manager->expects($this->once())
			->method('canShare')
			->with($share)
			->willReturn(true);
		$manager->expects($this->once())
			->method('generalCreateChecks')
			->with($share);;
		$manager->expects($this->once())
			->method('linkCreateChecks')
			->with($share);;
		$manager->expects($this->once())
			->method('pathCreateChecks')
			->with($path);
		$manager->expects($this->once())
			->method('validateExpirationDate')
			->with($share);
		$manager->expects($this->once())
			->method('verifyPassword')
			->with('password');

		$this->hasher->expects($this->once())
			->method('hash')
			->with('password')
			->willReturn('hashed');

		$this->secureRandom->method('getMediumStrengthGenerator')
			->will($this->returnSelf());
		$this->secureRandom->method('generate')
			->willReturn('token');

		$this->defaultProvider
			->expects($this->once())
			->method('create')
			->with($share)
			->will($this->returnCallback(function(Share $share) {
				return $share->setId(42);
			}));

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['pre', 'post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'pre_shared',  $hookListner, 'pre');
		\OCP\Util::connectHook('OCP\Share', 'post_shared', $hookListner, 'post');

		$hookListnerExpectsPre = [
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => \OCP\Share::SHARE_TYPE_LINK,
			'uidOwner' => 'sharedBy',
			'permissions' => 31,
			'fileSource' => 1,
			'expiration' => $date,
			'token' => 'token',
			'run' => true,
			'error' => '',
			'itemTarget' => '/target',
			'shareWith' => null,
		];

		$hookListnerExpectsPost = [
			'itemType' => 'file',
			'itemSource' => 1,
			'shareType' => \OCP\Share::SHARE_TYPE_LINK,
			'uidOwner' => 'sharedBy',
			'permissions' => 31,
			'fileSource' => 1,
			'expiration' => $date,
			'token' => 'token',
			'id' => 42,
			'itemTarget' => '/target',
			'fileTarget' => '/target',
			'shareWith' => null,
		];

		$hookListner->expects($this->once())
			->method('pre')
			->with($this->equalTo($hookListnerExpectsPre));
		$hookListner->expects($this->once())
			->method('post')
			->with($this->equalTo($hookListnerExpectsPost));

		/** @var IShare $share */
		$share = $manager->createShare($share);

		$this->assertSame('shareOwner', $share->getShareOwner());
		$this->assertEquals('/target', $share->getTarget());
		$this->assertSame($date, $share->getExpirationDate());
		$this->assertEquals('token', $share->getToken());
		$this->assertEquals('hashed', $share->getPassword());
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage I won't let you share
	 */
	public function testCreateShareHookError() {
		$manager = $this->createManagerMock()
			->setMethods([
				'canShare',
				'generalCreateChecks',
				'userCreateChecks',
				'pathCreateChecks',
			])
			->getMock();

		$shareOwner = $this->getMock('\OCP\IUser');
		$shareOwner->method('getUID')->willReturn('shareOwner');

		$path = $this->getMock('\OCP\Files\File');
		$path->method('getOwner')->willReturn($shareOwner);
		$path->method('getName')->willReturn('target');

		$share = $this->createShare(
			null,
			\OCP\Share::SHARE_TYPE_USER,
			$path,
			'sharedWith',
			'sharedBy',
			null,
			\OCP\Constants::PERMISSION_ALL);

		$manager->expects($this->once())
			->method('canShare')
			->with($share)
			->willReturn(true);
		$manager->expects($this->once())
			->method('generalCreateChecks')
			->with($share);;
		$manager->expects($this->once())
			->method('userCreateChecks')
			->with($share);;
		$manager->expects($this->once())
			->method('pathCreateChecks')
			->with($path);

		$share->expects($this->once())
			->method('setShareOwner')
			->with('shareOwner');
		$share->expects($this->once())
			->method('setTarget')
			->with('/target');

		$dummy = new DummyCreate();
		\OCP\Util::connectHook('OCP\Share', 'pre_shared', $dummy, 'listner');

		$manager->createShare($share);
	}

	public function testGetShareByToken() {
		$factory = $this->getMock('\OCP\Share\IProviderFactory');

		$manager = new Manager(
			$this->logger,
			$this->config,
			$this->secureRandom,
			$this->hasher,
			$this->mountManager,
			$this->groupManager,
			$this->l,
			$factory,
			$this->userManager,
			$this->rootFolder
		);

		$share = $this->getMock('\OCP\Share\IShare');

		$factory->expects($this->once())
			->method('getProviderForType')
			->with(\OCP\Share::SHARE_TYPE_LINK)
			->willReturn($this->defaultProvider);

		$this->defaultProvider->expects($this->once())
			->method('getShareByToken')
			->with('token')
			->willReturn($share);

		$ret = $manager->getShareByToken('token');
		$this->assertSame($share, $ret);
	}

	public function testCheckPasswordNoLinkShare() {
		$share = $this->getMock('\OCP\Share\IShare');
		$share->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_USER);
		$this->assertFalse($this->manager->checkPassword($share, 'password'));
	}

	public function testCheckPasswordNoPassword() {
		$share = $this->getMock('\OCP\Share\IShare');
		$share->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_LINK);
		$this->assertFalse($this->manager->checkPassword($share, 'password'));

		$share->method('getPassword')->willReturn('password');
		$this->assertFalse($this->manager->checkPassword($share, null));
	}

	public function testCheckPasswordInvalidPassword() {
		$share = $this->getMock('\OCP\Share\IShare');
		$share->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_LINK);
		$share->method('getPassword')->willReturn('password');

		$this->hasher->method('verify')->with('invalidpassword', 'password', '')->willReturn(false);

		$this->assertFalse($this->manager->checkPassword($share, 'invalidpassword'));
	}

	public function testCheckPasswordValidPassword() {
		$share = $this->getMock('\OCP\Share\IShare');
		$share->method('getShareType')->willReturn(\OCP\Share::SHARE_TYPE_LINK);
		$share->method('getPassword')->willReturn('passwordHash');

		$this->hasher->method('verify')->with('password', 'passwordHash', '')->willReturn(true);

		$this->assertTrue($this->manager->checkPassword($share, 'password'));
	}

	public function testCheckPasswordUpdateShare() {
		$share = $this->manager->newShare();
		$share->setShareType(\OCP\Share::SHARE_TYPE_LINK)
			->setPassword('passwordHash');

		$this->hasher->method('verify')->with('password', 'passwordHash', '')
			->will($this->returnCallback(function($pass, $hash, &$newHash) {
				$newHash = 'newHash';

				return true;
			}));

		$this->defaultProvider->expects($this->once())
			->method('update')
			->with($this->callback(function (\OCP\Share\IShare $share) {
				return $share->getPassword() === 'newHash';
			}));

		$this->assertTrue($this->manager->checkPassword($share, 'password'));
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage The Share API is disabled
	 */
	public function testUpdateShareCantShare() {
		$manager = $this->createManagerMock()
			->setMethods(['canShare'])
			->getMock();

		$manager->expects($this->once())->method('canShare')->willReturn(false);
		$share = $this->manager->newShare();
		$manager->updateShare($share);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage Can't change share type
	 */
	public function testUpdateShareCantChangeShareType() {
		$manager = $this->createManagerMock()
			->setMethods([
				'canShare',
				'getShareById'
			])
			->getMock();

		$originalShare = $this->manager->newShare();
		$originalShare->setShareType(\OCP\Share::SHARE_TYPE_GROUP);

		$manager->expects($this->once())->method('canShare')->willReturn(true);
		$manager->expects($this->once())->method('getShareById')->with('foo:42')->willReturn($originalShare);

		$share = $this->manager->newShare();
		$share->setProviderId('foo')
			->setId('42')
			->setShareType(\OCP\Share::SHARE_TYPE_USER);

		$manager->updateShare($share);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage Can only update recipient on user shares
	 */
	public function testUpdateShareCantChangeRecipientForGroupShare() {
		$manager = $this->createManagerMock()
			->setMethods([
				'canShare',
				'getShareById'
			])
			->getMock();

		$originalShare = $this->manager->newShare();
		$originalShare->setShareType(\OCP\Share::SHARE_TYPE_GROUP)
			->setSharedWith('origGroup');

		$manager->expects($this->once())->method('canShare')->willReturn(true);
		$manager->expects($this->once())->method('getShareById')->with('foo:42')->willReturn($originalShare);

		$share = $this->manager->newShare();
		$share->setProviderId('foo')
			->setId('42')
			->setShareType(\OCP\Share::SHARE_TYPE_GROUP)
			->setSharedWith('newGroup');

		$manager->updateShare($share);
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage Can't share with the share owner
	 */
	public function testUpdateShareCantShareWithOwner() {
		$manager = $this->createManagerMock()
			->setMethods([
				'canShare',
				'getShareById'
			])
			->getMock();

		$originalShare = $this->manager->newShare();
		$originalShare->setShareType(\OCP\Share::SHARE_TYPE_USER)
			->setSharedWith('sharedWith');

		$manager->expects($this->once())->method('canShare')->willReturn(true);
		$manager->expects($this->once())->method('getShareById')->with('foo:42')->willReturn($originalShare);

		$share = $this->manager->newShare();
		$share->setProviderId('foo')
			->setId('42')
			->setShareType(\OCP\Share::SHARE_TYPE_USER)
			->setSharedWith('newUser')
			->setShareOwner('newUser');

		$manager->updateShare($share);
	}

	public function testUpdateShareUser() {
		$manager = $this->createManagerMock()
			->setMethods([
				'canShare',
				'getShareById',
				'generalCreateChecks',
				'userCreateChecks',
				'pathCreateChecks',
			])
			->getMock();

		$originalShare = $this->manager->newShare();
		$originalShare->setShareType(\OCP\Share::SHARE_TYPE_USER)
			->setSharedWith('origUser')
			->setPermissions(1);

		$node = $this->getMock('\OCP\Files\File');
		$node->method('getId')->willReturn(100);
		$node->method('getPath')->willReturn('/newUser/files/myPath');

		$manager->expects($this->once())->method('canShare')->willReturn(true);
		$manager->expects($this->once())->method('getShareById')->with('foo:42')->willReturn($originalShare);

		$share = $this->manager->newShare();
		$share->setProviderId('foo')
			->setId('42')
			->setShareType(\OCP\Share::SHARE_TYPE_USER)
			->setSharedWith('origUser')
			->setShareOwner('newUser')
			->setSharedBy('sharer')
			->setPermissions(31)
			->setNode($node);

		$this->defaultProvider->expects($this->once())
			->method('update')
			->with($share)
			->willReturn($share);

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'post_set_expiration_date', $hookListner, 'post');
		$hookListner->expects($this->never())->method('post');

		$this->rootFolder->method('getUserFolder')->with('newUser')->will($this->returnSelf());
		$this->rootFolder->method('getRelativePath')->with('/newUser/files/myPath')->willReturn('/myPath');

		$hookListner2 = $this->getMockBuilder('Dummy')->setMethods(['post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'post_update_permissions', $hookListner2, 'post');
		$hookListner2->expects($this->once())->method('post')->with([
			'itemType' => 'file',
			'itemSource' => 100,
			'shareType' => \OCP\Share::SHARE_TYPE_USER,
			'shareWith' => 'origUser',
			'uidOwner' => 'sharer',
			'permissions' => 31,
			'path' => '/myPath',
		]);

		$manager->updateShare($share);
	}

	public function testUpdateShareGroup() {
		$manager = $this->createManagerMock()
			->setMethods([
				'canShare',
				'getShareById',
				'generalCreateChecks',
				'groupCreateChecks',
				'pathCreateChecks',
			])
			->getMock();

		$originalShare = $this->manager->newShare();
		$originalShare->setShareType(\OCP\Share::SHARE_TYPE_GROUP)
			->setSharedWith('origUser')
			->setPermissions(31);

		$manager->expects($this->once())->method('canShare')->willReturn(true);
		$manager->expects($this->once())->method('getShareById')->with('foo:42')->willReturn($originalShare);

		$node = $this->getMock('\OCP\Files\File');

		$share = $this->manager->newShare();
		$share->setProviderId('foo')
			->setId('42')
			->setShareType(\OCP\Share::SHARE_TYPE_GROUP)
			->setSharedWith('origUser')
			->setShareOwner('owner')
			->setNode($node)
			->setPermissions(31);

		$this->defaultProvider->expects($this->once())
			->method('update')
			->with($share)
			->willReturn($share);

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'post_set_expiration_date', $hookListner, 'post');
		$hookListner->expects($this->never())->method('post');

		$hookListner2 = $this->getMockBuilder('Dummy')->setMethods(['post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'post_update_permissions', $hookListner2, 'post');
		$hookListner2->expects($this->never())->method('post');

		$manager->updateShare($share);
	}

	public function testUpdateShareLink() {
		$manager = $this->createManagerMock()
			->setMethods([
				'canShare',
				'getShareById',
				'generalCreateChecks',
				'linkCreateChecks',
				'pathCreateChecks',
				'verifyPassword',
				'validateExpirationDate',
			])
			->getMock();

		$originalShare = $this->manager->newShare();
		$originalShare->setShareType(\OCP\Share::SHARE_TYPE_LINK)
			->setPermissions(15);

		$tomorrow = new \DateTime();
		$tomorrow->setTime(0,0,0);
		$tomorrow->add(new \DateInterval('P1D'));

		$file = $this->getMock('OCP\Files\File', [], [], 'File');
		$file->method('getId')->willReturn(100);

		$share = $this->manager->newShare();
		$share->setProviderId('foo')
			->setId('42')
			->setShareType(\OCP\Share::SHARE_TYPE_LINK)
			->setSharedBy('owner')
			->setShareOwner('owner')
			->setPassword('password')
			->setExpirationDate($tomorrow)
			->setNode($file)
			->setPermissions(15);

		$manager->expects($this->once())->method('canShare')->willReturn(true);
		$manager->expects($this->once())->method('getShareById')->with('foo:42')->willReturn($originalShare);
		$manager->expects($this->once())->method('validateExpirationDate')->with($share);

		$this->defaultProvider->expects($this->once())
			->method('update')
			->with($share)
			->willReturn($share);

		$hookListner = $this->getMockBuilder('Dummy')->setMethods(['post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'post_set_expiration_date', $hookListner, 'post');
		$hookListner->expects($this->once())->method('post')->with([
			'itemType' => 'file',
			'itemSource' => 100,
			'date' => $tomorrow,
			'uidOwner' => 'owner',
		]);

		$hookListner2 = $this->getMockBuilder('Dummy')->setMethods(['post'])->getMock();
		\OCP\Util::connectHook('OCP\Share', 'post_update_permissions', $hookListner2, 'post');
		$hookListner2->expects($this->never())->method('post');


		$manager->updateShare($share);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Can't change target of link share
	 */
	public function testMoveShareLink() {
		$share = $this->manager->newShare();
		$share->setShareType(\OCP\Share::SHARE_TYPE_LINK);

		$recipient = $this->getMock('\OCP\IUser');

		$this->manager->moveShare($share, $recipient);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Invalid recipient
	 */
	public function testMoveShareUserNotRecipient() {
		$share = $this->manager->newShare();
		$share->setShareType(\OCP\Share::SHARE_TYPE_USER);

		$share->setSharedWith('sharedWith');

		$this->manager->moveShare($share, 'recipient');
	}

	public function testMoveShareUser() {
		$share = $this->manager->newShare();
		$share->setShareType(\OCP\Share::SHARE_TYPE_USER);

		$share->setSharedWith('recipient');

		$this->defaultProvider->method('move')->with($share, 'recipient')->will($this->returnArgument(0));

		$this->manager->moveShare($share, 'recipient');
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Invalid recipient
	 */
	public function testMoveShareGroupNotRecipient() {
		$share = $this->manager->newShare();
		$share->setShareType(\OCP\Share::SHARE_TYPE_GROUP);

		$sharedWith = $this->getMock('\OCP\IGroup');
		$share->setSharedWith('shareWith');

		$recipient = $this->getMock('\OCP\IUser');
		$sharedWith->method('inGroup')->with($recipient)->willReturn(false);

		$this->groupManager->method('get')->with('shareWith')->willReturn($sharedWith);
		$this->userManager->method('get')->with('recipient')->willReturn($recipient);

		$this->manager->moveShare($share, 'recipient');
	}

	public function testMoveShareGroup() {
		$share = $this->manager->newShare();
		$share->setShareType(\OCP\Share::SHARE_TYPE_GROUP);

		$group = $this->getMock('\OCP\IGroup');
		$share->setSharedWith('group');

		$recipient = $this->getMock('\OCP\IUser');
		$group->method('inGroup')->with($recipient)->willReturn(true);

		$this->groupManager->method('get')->with('group')->willReturn($group);
		$this->userManager->method('get')->with('recipient')->willReturn($recipient);

		$this->defaultProvider->method('move')->with($share, 'recipient')->will($this->returnArgument(0));

		$this->manager->moveShare($share, 'recipient');
	}
}

class DummyPassword {
	public function listner($array) {
		$array['accepted'] = false;
		$array['message'] = 'password not accepted';
	}
}

class DummyCreate {
	public function listner($array) {
		$array['run'] = false;
		$array['error'] = 'I won\'t let you share!';
	}
}

class DummyFactory implements IProviderFactory {

	/** @var IShareProvider */
	private $provider;

	public function __construct(\OCP\IServerContainer $serverContainer) {

	}

	/**
	 * @param IShareProvider $provider
	 */
	public function setProvider($provider) {
		$this->provider = $provider;
	}

	/**
	 * @param string $id
	 * @return IShareProvider
	 */
	public function getProvider($id) {
		return $this->provider;
	}

	/**
	 * @param int $shareType
	 * @return IShareProvider
	 */
	public function getProviderForType($shareType) {
		return $this->provider;
	}
}