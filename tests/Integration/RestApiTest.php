<?php
namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class RestApiTest extends WP_UnitTestCase {
    private string $namespace = '/photolab/v1';
    private int $admin_id;
    
    public function setUp(): void {
        parent::setUp();
        $this->admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin_id );
    }
    
    private function do_request( string $method, string $route, array $params = [] ): array {
        $request = new \WP_REST_Request( $method, $this->namespace . $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $response = rest_do_request( $request );
        return [
            'status' => $response->get_status(),
            'body'   => $response->get_data(),
        ];
    }
    
    public function test_get_settings_returns_expected_keys(): void {
        $result = $this->do_request( 'GET', '/settings' );
        $this->assertEquals( 200, $result['status'] );
        $body = $result['body'];
        $this->assertIsArray( $body );
        $this->assertArrayHasKey( 'image_engine', $body );
        $this->assertArrayHasKey( 'watermark_active', $body );
        $this->assertArrayHasKey( 'watermark_url', $body );
    }
    
    public function test_get_settings_requires_admin(): void {
        wp_set_current_user( 0 );
        $result = $this->do_request( 'GET', '/settings' );
		$this->assertEquals( 403, $result['status'] );
    }
    
    public function test_get_albums_returns_list(): void {
        $result = $this->do_request( 'GET', '/albums' );
        $this->assertEquals( 200, $result['status'] );
    }
}
