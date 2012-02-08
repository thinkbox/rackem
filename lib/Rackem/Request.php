<?php
namespace Rackem;

class Request
{
	public $env;
	
	public function __construct($env = array())
	{
		$this->env = $env;
	}
	
	public function body()
	{
		return $this->env["rack.input"];
	}
	
	public function content_type()
	{
		return (isset($this->env["CONTENT_TYPE"]))? $this->env["CONTENT_TYPE"] : null;
	}
	
	public function form_data()
	{
		return ($this->env["REQUEST_METHOD"] == "POST");
	}
	
	public function get()
	{
		if($this->env["rack.request.query_string"] == $this->query_string())
			return $this->env["rack.request.query_hash"];
		$this->env["rack.request.query_string"] = $this->query_string();
		$this->env["rack.request.query_hash"] = $this->parse_query($this->query_string());
		return $this->env["rack.request.query_hash"];
	}
	
	public function media_type()
	{
		return $this->content_type() && strtolower(array_shift(split("/\s*[;,]\s*/",2,$this->content_type())));
	}
	
	public function media_type_params()
	{
		if( is_null($this->content_type()) ) return array();
		//TODO	
	}
	
	public function content_charset()
	{
		$params = $this->media_type_params();
		return (isset($params['charset']))? $params['charset'] : null;
	}
	
	public function params()
	{
		
	}
	
	public function parseable_data()
	{
		
	}
	
	public function post()
	{
		if($this->env["rack.request.form_input"] == $this->env["rack.input"])
			return $this->env["rack.request.form_hash"];
		return null;
	}
	
	public function query_string()
	{
		return $this->env["QUERY_STRING"];
	}
	
	public function scheme()
	{
		return $this->env["rack.url_scheme"];
	}
	
	public function ssl()
	{
		return $this->scheme() == "https";
	}
	
	public function user_agent()
	{
		return $this->env["HTTP_USER_AGENT"];
	}
	
	//private
	private function parse_query($qs)
	{
		return Utils::parse_nested_query($qs);
	}
	
}