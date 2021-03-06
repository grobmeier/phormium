<?php

namespace Phormium\Tests;

use \Phormium\Filter;
use \Phormium\DB;
use \Phormium\ColumnFilter;
use \Phormium\CompositeFilter;
use \Phormium\Meta;
use \Phormium\Aggregate;
use \Phormium\QuerySet;

use \Phormium\Tests\Models\Person;

class QuerySetTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        DB::configure(PHORMIUM_CONFIG_FILE);
    }

    public function testCloneQS()
    {
        $qs1 = Person::objects();
        $qs2 = $qs1->all();

        self::assertEquals($qs1, $qs2);
        self::assertNotSame($qs1, $qs2);
        self::assertNull($qs1->getFilter());
        self::assertNull($qs2->getFilter());
        self::assertEmpty($qs1->getOrder());
        self::assertEmpty($qs2->getOrder());
    }

    public function testFilterQS()
    {
        $f = new ColumnFilter('name', '=', 'x');
        $qs1 = Person::objects();
        $qs2 = $qs1->filter('name', '=', 'x');

        self::assertNotEquals($qs1, $qs2);
        self::assertNotSame($qs1, $qs2);

        self::assertInstanceOf("\\Phormium\\CompositeFilter", $qs2->getFilter());
        self::assertCount(1, $qs2->getFilter()->getFilters());

        self::assertEmpty($qs1->getOrder());
        self::assertEmpty($qs2->getOrder());

        $expected = Filter::_and($f);
        $actual = $qs2->getFilter();
        self::assertEquals($expected, $actual);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Invalid filter: Column [x] does not exist in table [person].
     */
    public function testFilterInvalidColumn()
    {
        Person::objects()->filter('x', '=', 'x');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Unknown filter operation [!!!].
     */
    public function testFilterInvalidOperation()
    {
        Person::objects()->filter('name', '!!!', 'x')->fetch();
    }

    public function testOrderQS()
    {
        $qs1 = Person::objects();
        $qs2 = $qs1->orderBy('name', 'desc');

        self::assertNotEquals($qs1, $qs2);
        self::assertNotSame($qs1, $qs2);

        self::assertNull($qs1->getFilter());
        self::assertNull($qs2->getFilter());

        self::assertEmpty($qs1->getOrder());

        $expected = array('name desc');
        $actual = $qs2->getOrder();
        self::assertSame($expected, $actual);

        $qs3 = $qs2->orderBy('id');
        $expected = array('name desc', 'id asc');
        $actual = $qs3->getOrder();
        self::assertSame($expected, $actual);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Invalid order direction [!!!]. Expected 'asc' or 'desc'.
     */
    public function testOrderInvalidDirection()
    {
        Person::objects()->orderBy('name', '!!!')->fetch();
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Cannot order by column [xxx] because it does not exist in table [person].
     */
    public function testOrderInvalidColumn()
    {
        Person::objects()->orderBy('xxx', 'asc')->fetch();
    }

    public function testAggregates()
    {
        // Create some sample data
        $uniq = uniqid('agg');

        $p1 = array(
            'name' => "{$uniq}_1",
            'birthday' => '2000-01-01',
            'income' => 10000
        );

        $p2 = array(
            'name' => "{$uniq}_2",
            'birthday' => '2001-01-01',
            'income' => 20000
        );

        $p3 = array(
            'name' => "{$uniq}_3",
            'birthday' => '2002-01-01',
            'income' => 30000
        );

        self::assertFalse(Person::objects()->filter('birthday', '=', '2000-01-01')->exists());

        Person::fromArray($p1)->save();
        Person::fromArray($p2)->save();
        Person::fromArray($p3)->save();

        self::assertTrue(Person::objects()->filter('birthday', '=', '2000-01-01')->exists());

        // Query set filtering the above created records
        $qs = Person::objects()->filter('name', 'like', "$uniq%");

        $count = $qs->count();
        self::assertSame(3, $count);

        self::assertSame('2000-01-01', $qs->min('birthday'));
        self::assertSame('2002-01-01', $qs->max('birthday'));

        self::assertEquals(10000, $qs->min('income'));
        self::assertEquals(20000, $qs->avg('income'));
        self::assertEquals(60000, $qs->sum('income'));
        self::assertEquals(30000, $qs->max('income'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Invalid aggregate type [xxx].
     */
    public function testAggregatesInvalidType()
    {
        $agg = new Aggregate('xxx', 'yyy');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Error forming aggregate query. Column [xxx] does not exist in table [person].
     */
    public function testAggregatesInvalidColumn()
    {
        Person::objects()->avg('xxx');
    }

    public function testBatch()
    {
        // Create some sample data
        $uniq = uniqid('batch');

        $p1 = array(
            'name' => "{$uniq}_1",
            'income' => 10000
        );

        $p2 = array(
            'name' => "{$uniq}_2",
            'income' => 20000
        );

        $p3 = array(
            'name' => "{$uniq}_3",
            'income' => 30000
        );

        $qs = Person::objects()->filter('name', 'like', "{$uniq}%");

        self::assertFalse($qs->exists());
        self::assertSame(0, $qs->count());

        Person::fromArray($p1)->save();
        Person::fromArray($p2)->save();
        Person::fromArray($p3)->save();

        self::assertTrue($qs->exists());
        self::assertSame(3, $qs->count());

        // Give everybody a raise!
        $count = $qs->update(
            array(
                'income' => 5000
            )
        );

        self::assertSame(3, $count);

        $persons = $qs->fetch();
        foreach ($persons as $person) {
            self::assertEquals(5000, $person->income);
        }

        // Delete
        $count = $qs->delete();
        self::assertSame(3, $count);

        // Check deleted
        self::assertFalse($qs->exists());
        self::assertSame(0, $qs->count());

        // Repeated delete should yield 0 count
        $count = $qs->delete();
        self::assertSame(0, $count);
    }

    public function testGetMeta()
    {
        // Just to improve code coverage
        $meta1 = Person::getMeta();
        $meta2 = Person::objects()->getMeta();

        self::assertSame($meta1, $meta2);
    }

    public function testLimitedFetch()
    {
        // Create some sample data
        $uniq = uniqid('limit');

        $persons = array(
            Person::fromArray(array('name' => "{$uniq}_1")),
            Person::fromArray(array('name' => "{$uniq}_2")),
            Person::fromArray(array('name' => "{$uniq}_3")),
            Person::fromArray(array('name' => "{$uniq}_4")),
            Person::fromArray(array('name' => "{$uniq}_5")),
        );

        foreach ($persons as $person) {
            $person->save();
        }

        $qs = Person::objects()
            ->filter('name', 'like', "{$uniq}%")
            ->orderBy('name');

        self::assertEquals(array_slice($persons, 0, 2), $qs->limit(2)->fetch());
        self::assertEquals(array_slice($persons, 0, 2), $qs->limit(2, 0)->fetch());
        self::assertEquals(array_slice($persons, 1, 2), $qs->limit(2, 1)->fetch());
        self::assertEquals(array_slice($persons, 2, 2), $qs->limit(2, 2)->fetch());
        self::assertEquals(array_slice($persons, 3, 2), $qs->limit(2, 3)->fetch());
        self::assertEquals(array_slice($persons, 0, 1), $qs->limit(1)->fetch());
        self::assertEquals(array_slice($persons, 0, 1), $qs->limit(1, 0)->fetch());
        self::assertEquals(array_slice($persons, 1, 1), $qs->limit(1, 1)->fetch());
        self::assertEquals(array_slice($persons, 2, 1), $qs->limit(1, 2)->fetch());
        self::assertEquals(array_slice($persons, 3, 1), $qs->limit(1, 3)->fetch());
        self::assertEquals(array_slice($persons, 4, 1), $qs->limit(1, 4)->fetch());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Limit must be an integer or null.
     */
    public function testLimitedFetchWrongLimit1()
    {
        Person::objects()->limit(1.1);
    }

/**
     * @expectedException \Exception
     * @expectedExceptionMessage Limit must be an integer or null.
     */
    public function testLimitedFetchWrongLimit2()
    {
        Person::objects()->limit("a");
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Offset must be an integer or null.
     */
    public function testLimitedFetchWrongOffset1()
    {
        Person::objects()->limit(1, 1.1);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Offset must be an integer or null.
     */
    public function testLimitedFetchWrongOffset2()
    {
        Person::objects()->limit(1, "a");
    }
}
