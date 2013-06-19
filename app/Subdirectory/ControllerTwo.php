<?php

    /**
     * @Path("/two")
     */
    class ControllerTwo extends Controller {

        /**
         * @Path("/")
         */
        public function index() {
            echo "Controller two, index";
        }

        /**
         * @Path("/method/{2}/{1}")
         */
        public function method($a, $b)
        {
            echo "Controller two, method($a, $b)";
        }
    }