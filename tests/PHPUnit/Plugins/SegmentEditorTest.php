<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
use Piwik\Piwik;
use Piwik\Access;
use Piwik\Date;
use Piwik\Plugins\SegmentEditor\API;
use Piwik\Plugins\SitesManager\API as SitesManagerAPI;

class SegmentEditorTest extends DatabaseTestCase
{
    public function setUp()
    {
        parent::setUp();

        \Piwik\PluginsManager::getInstance()->loadPlugin('SegmentEditor');
        \Piwik\PluginsManager::getInstance()->installLoadedPlugins();

        // setup the access layer
        $pseudoMockAccess = new FakeAccess;
        FakeAccess::setIdSitesView(array(1, 2));
        FakeAccess::setIdSitesAdmin(array(3, 4));

        //finally we set the user as a super user by default
        FakeAccess::$superUser = true;
        FakeAccess::$superUserLogin = 'superusertest';
        Access::setSingletonInstance($pseudoMockAccess);

        SitesManagerAPI::getInstance()->addSite('test', 'http://example.org');
    }

    public function tearDown()
    {
        $dao = Piwik_Db_Factory::getDao('segment');
        $dao->uninstall();
        parent::tearDown();
    }

    public function testAddInvalidSegment_ShouldThrow()
    {
        try {
            API::getInstance()->add('name', 'test==test2');
            $this->fail("Exception not raised.");
        } catch (Exception $expected) {
        }
        try {
            API::getInstance()->add('name', 'test');
            $this->fail("Exception not raised.");
        } catch (Exception $expected) {
        }
    }

    public function test_AddAndGet_SimpleSegment()
    {
        $name = 'name';
        $definition = 'searches>1,visitIp!=127.0.0.1';
        $idSegment = API::getInstance()->add($name, $definition);
        $this->assertEquals($idSegment, 1);
        $segment = API::getInstance()->get($idSegment);
        unset($segment['ts_created']);
        $expected = array(
            'idsegment' => 1,
            'name' => $name,
            'definition' => $definition,
            'login' => 'superUserLogin',
            'enable_all_users' => '0',
            'enable_only_idsite' => '0',
            'auto_archive' => '0',
            'ts_last_edit' => null,
            'deleted' => '0',
        );

        $this->assertEquals($segment, $expected);
    }

    public function test_AddAndGet_AnotherSegment()
    {
        $name = 'name';
        $definition = 'searches>1,visitIp!=127.0.0.1';
        $idSegment = API::getInstance()->add($name, $definition, $idSite = 1, $autoArchive = 1, $enabledAllUsers = 1);
        $this->assertEquals($idSegment, 1);

        // Testing get()
        $segment = API::getInstance()->get($idSegment);
        $expected = array(
            'idsegment' => '1',
            'name' => $name,
            'definition' => $definition,
            'login' => 'superUserLogin',
            'enable_all_users' => '1',
            'enable_only_idsite' => '1',
            'auto_archive' => '1',
            'ts_last_edit' => null,
            'deleted' => '0',
        );
        unset($segment['ts_created']);
        $this->assertEquals($segment, $expected);

        // There is a segment to process for this particular site
        $segments = API::getInstance()->getAll($idSite, $autoArchived = true);
        unset($segments[0]['ts_created']);
        $this->assertEquals($segments, array($expected));

        // There is no segment to process for a non existing site
        try {
            $segments = API::getInstance()->getAll(33, $autoArchived = true);
            $this->fail();
        } catch(Exception $e) {
            // expected
        }

        // There is no segment to process across all sites
        $segments = API::getInstance()->getAll($idSite = false, $autoArchived = true);
        $this->assertEquals($segments, array());
    }

    public function test_UpdateSegment()
    {
        $name = 'name"';
        $definition = 'searches>1,visitIp!=127.0.0.1';
        $nameSegment1 = 'hello';
        $idSegment1 = API::getInstance()->add($nameSegment1, 'searches==0', $idSite = 1, $autoArchive = 1, $enabledAllUsers = 1);
        $idSegment2 = API::getInstance()->add($name, $definition, $idSite = 1, $autoArchive = 1, $enabledAllUsers = 1);

        $updatedSegment = array(
            'idsegment' => $idSegment2,
            'name' =>   'NEW name',
            'definition' =>  'searches==0',
            'enable_only_idsite' => '0',
            'enable_all_users' => '0',
            'auto_archive' => '0',
            'ts_last_edit' => Date::now()->getDatetime(),
            'ts_created' => Date::now()->getDatetime(),
            'login' => Piwik::getCurrentUserLogin(),
            'deleted' => '0',
        );
        API::getInstance()->update($idSegment2,
            $updatedSegment['name'],
            $updatedSegment['definition'],
            $updatedSegment['enable_only_idsite'],
            $updatedSegment['auto_archive'],
            $updatedSegment['enable_all_users']
        );

        $newSegment = API::getInstance()->get($idSegment2);
        $this->assertEquals($newSegment, $updatedSegment);

        // Check the other segmenet was not updated
        $newSegment = API::getInstance()->get($idSegment1);
        $this->assertEquals($newSegment['name'], $nameSegment1);
    }

    public function test_deleteSegment()
    {
        $idSegment1 = API::getInstance()->add('name 1', 'searches==0', $idSite = 1, $autoArchive = 1, $enabledAllUsers = 1);
        $idSegment2 = API::getInstance()->add('name 2', 'searches>1,visitIp!=127.0.0.1', $idSite = 1, $autoArchive = 1, $enabledAllUsers = 1);

        $deleted = API::getInstance()->delete($idSegment2);
        $this->assertTrue($deleted);
        try {
            API::getInstance()->get($idSegment2);
            $this->fail("getting deleted segment should have failed");
        } catch(Exception $e) {
            // expected
        }

        // and this should work
        API::getInstance()->get($idSegment1);
    }
}
