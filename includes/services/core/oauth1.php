<?php

/**
 * Spec OAuth1 implementation for services using OAuth for authentication.
 * You will want to define request, access and authorize endpoints. Keyring
 * will walk the user through the OAuth dance. Once an access token is 
 * obtained, it's considered verified. You may still want to do an additional
 * request to get some details or verify something specific. To do that, hook
 * something to 'keyring_SERVICE_post_verification' (see Keyring_Service::verified())
 *
 * @package Keyring
 */
class Keyring_Service_OAuth1 extends Keyring_Service {
	protected $request_token_url    = ''; // @see ::set_endpoint()
	protected $request_token_method = 'GET';
	protected $access_token_url     = '';
	protected $access_token_method  = 'GET';
	protected $authorize_url        = '';
	protected $authorize_method     = 'GET';
	
	protected $key                  = null;
	protected $secret               = null;
	protected $consumer             = null;
	protected $signature_method     = null;
	protected $callback_url         = null;
	
	var $token                = null;
	
	function __construct() {
		parent::__construct();
		
		// Nonces for the callback URL, which is used during the verify step
		$kr_nonce = wp_create_nonce( 'keyring-verify' );
		$nonce = wp_create_nonce( 'keyring-verify-' . $this->get_name() );
		$this->callback_url = Keyring_Util::admin_url( $this->get_name(), array( 'action' => 'verify', 'kr_nonce' => $kr_nonce, 'nonce' => $nonce ) );
		
		if ( !class_exists( 'OAuthRequest' ) )
			require dirname( dirname( dirname( __FILE__ ) ) ) . '/oauth-php/OAuth.php';
	}
	
	function get_display( Keyring_Token $token ) {
		return $this->key;
	}
	
	function request_token() {
		Keyring_Util::debug( 'Keyring_Service_OAuth1::request_token()' );
		
		if ( !isset( $_REQUEST['nonce'] ) || !wp_verify_nonce( $_REQUEST['nonce'], 'keyring-request-' . $this->get_name() ) )
			wp_die( __( 'Invalid/missing request nonce.', 'keyring' ) );
		
		$request_token_url = add_query_arg( array( 'oauth_callback' => urlencode( $this->callback_url ) ), $this->request_token_url );
		
		// Set up OAuth request
		$req = OAuthRequest::from_consumer_and_token(
			$this->consumer,
			null,
			$this->request_token_method,
			$request_token_url,
			null
		);
		$req->sign_request(
			$this->signature_method,
			$this->consumer,
			null
		);
		
		$query = '';
		$parsed = parse_url( (string) $req );
		if ( !empty( $parsed['query'] ) && 'POST' == strtoupper( $this->request_token_method ) ) {
			$request_token_url = str_replace( '?' . $parsed['query'], '', (string) $req );
			$query = $parsed['query'];
		} else {
			$request_token_url = (string) $req;
		}
		
		
		// Get a request token
		switch ( strtoupper( $this->request_token_method ) ) {
		case 'GET':
			Keyring_Util::debug( "OAuth1 GET Request Token URL: $request_token_url" );
			$res = wp_remote_get( $request_token_url );
			break;
			
		case 'POST':
			Keyring_Util::debug( "OAuth1 POST Request Token URL: $request_token_url" );
			Keyring_Util::debug( $query );
			$res = wp_remote_post( $request_token_url, array( 'body' => $query, 'sslverify' => false ) );
			break;
			
		default:
			wp_die( __( 'Unsupported method specified for request_token.', 'keyring' ) );
			exit;
		}
		
		Keyring_Util::debug( $res );
		
		if ( 200 == wp_remote_retrieve_response_code( $res ) ) {
			// Get the values returned from the remote service
			$token = wp_remote_retrieve_body( $res );
			parse_str( trim( $token ), $token );
			
			// Set some values to the current domain so that we can retrieve them later
			$host = parse_url( site_url(), PHP_URL_HOST );
			$host = str_replace( 'www.', '', $host );
			
			// The token secret is important
			setcookie( "keyring_{$this->get_name()}", $token['oauth_token_secret'], ( time() + 60 * 60 ), '/', ".$host" );
			
			// Sometimes we have a verifier which we can use to confirm things later
			if ( isset( $token['oauth_verifier'] ) )
				setcookie( "keyring_{$this->get_name()}_verifier", $token['oauth_verifier'], ( time() + 60 * 60 ), '/', ".$host" );
		} else {
			Keyring::error(
				sprintf( __( 'There was a problem connecting to %s to create an authorized connection. Please try again in a moment.', 'keyring' ), $this->get_label() )
			);
			return false;
		}
		
		// Redirect user to get us an authorize token
		$authorize = $this->authorize_url . '?oauth_token=' . urlencode( $token['oauth_token'] );
		if ( $this->callback_url )
			$authorize .= '&oauth_callback=' . urlencode( $this->callback_url );
		
		Keyring_Util::debug( "OAuth Authorize Redirect: $authorize", KEYRING__DEBUG_NOTICE );
		wp_redirect( $authorize );
		exit;
	}
	
	function verify_token() {
		Keyring_Util::debug( 'Keyring_Service_OAuth1::verify_token()' );
		if ( !isset( $_REQUEST['nonce'] ) || !wp_verify_nonce( $_REQUEST['nonce'], 'keyring-verify-' . $this->get_name() ) )
			wp_die( __( 'Invalid/missing verification nonce.', 'keyring' ) );
		
		// Get an access token
		$token = isset( $_GET['oauth_token'] ) ? $_GET['oauth_token'] : false;

		$secret = $_COOKIE["keyring_{$this->get_name()}"];

		$access_token_url = $this->access_token_url;
		if ( !empty( $_GET['oauth_verifier'] ) )
			$access_token_url = add_query_arg( array( 'oauth_verifier' => urlencode( $_GET['oauth_verifier'] ) ), $access_token_url );
		
		// Set up a consumer token and make the request for an access_token
		$token = new OAuthConsumer( $token, $secret );
		$this->set_token( new Keyring_Token( $this->get_name(), $token, array() ) );
		$res = $this->request( $access_token_url, array( 'method' => $this->access_token_method ) );
		Keyring_Util::debug( $res );
		
		if ( !Keyring_Util::is_error( $res ) ) {
			parse_str( trim( $res ), $token );
			
			if ( method_exists( $this, 'custom_verify_token' ) )
				$this->custom_verify_token( $token );
			
			$meta = $this->build_token_meta( $token );
			
			$token = new OAuthToken( $token['oauth_token'], $token['oauth_token_secret'] );
			Keyring_Util::debug( $token );
			$id = $this->store_token( $token, $meta );
			$this->verified( $id );
		} else {
			Keyring::error(
				sprintf( __( 'There was a problem connecting to %s to create an authorized connection. Please try again in a moment.', 'keyring' ), $this->get_label() )
			);
			return false;
		}
	}
	
	function request( $url, array $params = array() ) {
		if ( $this->requires_token() && empty( $this->token ) )
			return new Keyring_Error( 'keyring-request-error', __( 'No token' ) );
		
		$method = 'GET';
		if ( isset( $params['method'] ) ) {
			$method = strtoupper( $params['method'] );
			unset( $params['method'] );
		}
		
		$query = '';
		$parsed = parse_url( $url );
		if ( !empty( $parsed['query'] ) && 'POST' == $method ) {
			$url = str_replace( $parsed['query'], '', $url );
			$query = $parsed['query'];
		}
		
		$token = $this->token->token ? $this->token->token : null;
		Keyring_Util::debug( $token );
		
		$req = OAuthRequest::from_consumer_and_token(
			$this->consumer,
			$token,
			$method,
			$url,
			$params
		);
		$req->sign_request(
			$this->signature_method,
			$this->consumer,
			$token
		);
		
		Keyring_Util::debug( "OAuth1 Request URL: $req" );
		switch ( $method ) {
		case 'GET':
			$res = wp_remote_get( (string) $req, $params );
			break;
			
		case 'POST':
			// TODO support POST (test post-body etc)
			$params = array_merge( array( 'body' => $query, 'sslverify' => false ), $params );
			$res = wp_remote_post( (string) $req, $params );
			break;
			
		default:
			wp_die( __( 'Unsupported method specified.', 'keyring' ) );
			exit;
		}
		
		Keyring_Util::debug( $res );
		if ( 200 == wp_remote_retrieve_response_code( $res ) ) {
			return wp_remote_retrieve_body( $res );
		} else {
			return new Keyring_Error( 'keyring-request-error', $res );
		}
	}
}
