<?php

/**
 * @Path("/")
 */
class ControllerTwo extends Controller
{

    /**
     * @Path("/")
     */
    public function index()
    {
        echo "Controller one, index";
    }

    /**
     * @Path("/method/{2}/{1}")
     */
    public function method($a, $b)
    {
        echo "Controller one, method($a, $b)";
    }
}