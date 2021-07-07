<?php
namespace Crystal\Test\Traits;

/**
 * source: https://gist.github.com/pokap/ac5fce3570c9bec3a87a8908bcbd0bbc
 */
trait ArrayTestCaseTrait
{
    /**
     * Asserts that two associative arrays are similar.
     *
     * Both arrays must have the same indexes with identical values
     * without respect to key ordering
     */
    protected function assertArraySimilar(array $expected, array $array)
    {
        $this->assertTrue(count(array_diff_key($array, $expected)) === 0);

        foreach ($expected as $key => $value) {
            if (is_array($value)) {
                $this->assertArraySimilar($value, $array[$key]);
                continue;
            }
            $this->assertTrue($value === $array[$key]);
        }
    }
}
