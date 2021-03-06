<?php
/**
 * Spiral, Core Components
 *
 * @author Wolfy-J
 */

namespace Spiral\Tests\ODM\Integration;

use MongoDB\BSON\ObjectID;
use Spiral\Tests\ODM\Fixtures\Admin;
use Spiral\Tests\ODM\Fixtures\DataPiece;
use Spiral\Tests\ODM\Fixtures\User;

class SaveTest extends BaseTest
{
    public function testSaveOne()
    {
        $this->assertSame(0, $this->odm->source(User::class)->count());

        $user = $this->odm->source(User::class)->create();
        $this->assertInstanceOf(User::class, $user);

        $user->name = 'Anton';
        $user->piece->value = 123;

        $user->save();
        $this->assertSame(1, $this->odm->source(User::class)->count());

        $user = new User();
        $user->save();

        $result = User::findByPK($user->primaryKey());
        $this->assertInstanceOf(User::class, $result);
    }

    public function testFindByPK()
    {
        $user = new User();
        $user->name = 'Anton';
        $user->piece->value = 123;
        $user->save();

        $result = User::findByPK($user->primaryKey());
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame('Anton', $user->name);
    }


    public function testFindOne()
    {
        $user = new User();
        $user->name = 'Anton';
        $user->piece->value = 123;
        $user->save();

        $result = User::findOne(['name' => 'Anton']);
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame('Anton', $user->name);
    }

    public function testFindOneWithSort()
    {
        $user = new User();
        $user->name = 'Anton';
        $user->piece->something = 123;
        $user->save();

        $user = new User();
        $user->name = 'Anton';
        $user->piece->something = 250;
        $user->save();

        $result = User::findOne(['name' => 'Anton'], ['piece.something' => 1]);
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame(123, $result->piece->something);

        $result = User::findOne(['name' => 'Anton'], ['piece.something' => -1]);
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame(250, $result->piece->something);
    }

    public function testSaveOneChild()
    {
        $this->assertSame(0, $this->odm->source(User::class)->count());

        $user = $this->odm->source(Admin::class)->create();
        $this->assertInstanceOf(Admin::class, $user);

        $user->name = 'John';
        $user->piece->value = 123;
        $user->pieces->add(new DataPiece(['value' => 900], $this->odm, null));

        $user->save();
        $this->assertSame(1, $this->odm->source(User::class)->count());
    }

    public function testSaveMultiple()
    {
        $this->assertSame(0, $this->odm->source(User::class)->count());

        for ($i = 0; $i < 10; $i++) {
            $user = $this->odm->source(User::class)->create();
            $this->assertInstanceOf(User::class, $user);

            $user->name = 'Anton';
            $user->piece->value = $i;
            $user->save();
        }

        $this->assertSame(10, $this->odm->source(User::class)->count());
    }

    public function testSaveAsOneInstanceMustCauseAnUpdate()
    {
        $this->assertSame(0, $this->odm->source(User::class)->count());

        $user = $this->odm->source(User::class)->create();
        $this->assertInstanceOf(User::class, $user);

        for ($i = 0; $i < 10; $i++) {
            $user->name = 'Anton';
            $user->piece->value = $i;
            $user->save();
        }

        $this->assertSame(1, $this->odm->source(User::class)->count());
    }

    public function testDelete()
    {
        $this->assertSame(0, $this->odm->source(User::class)->count());

        for ($i = 0; $i < 10; $i++) {
            $user = $this->odm->source(User::class)->create();
            $this->assertInstanceOf(User::class, $user);

            $user->name = 'Anton';
            $user->piece->value = $i;
            $user->save();
        }

        $this->assertSame(10, $this->odm->source(User::class)->count());

        /**
         * @var User $user
         */
        foreach ($this->odm->source(User::class) as $user) {
            $this->assertInstanceOf(User::class, $user);

            $this->assertTrue($user->isLoaded());
            $this->assertInstanceOf(ObjectID::class, $user->primaryKey());
            $this->assertInstanceOf(ObjectID::class, $user->_id);

            $user->delete();
        }

        $this->assertSame(0, $this->odm->source(User::class)->count());
    }
}