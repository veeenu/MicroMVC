<?php

/******************************************************************************\
 *   microMVC - Lightweight MVC PHP implementation                            *
 *   Copyright (C) 2013  Andrea Venuta <venutawebdesign@gmail.com>            *
 *                                                                            *
 *   This program is free software: you can redistribute it and/or modify     *
 *   it under the terms of the GNU General Public License as published by     *
 *   the Free Software Foundation, either version 3 of the License, or        *
 *   (at your option) any later version.                                      *
 *                                                                            *
 *   This program is distributed in the hope that it will be useful,          *
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of           *
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            *
 *   GNU General Public License for more details.                             *
 *                                                                            *
 *   You should have received a copy of the GNU General Public License        *
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.    *
 *                                                                            *
\******************************************************************************/

class MVC
{
    /**
     * Recursively run glob() to find all PHP files in a directory
     *
     * @param $prefix   Directory where to perform the search
     * @return array    PHP files found in $prefix
     */
    private static function __rglob($prefix)
    {

        $phpFiles = array();

        // Get all the PHP files in the current directory
        foreach (glob($prefix . '/*.php') as $phpf)
            $phpFiles[] = $phpf;

        // Get all the subdirectories
        foreach (glob($prefix . '/*', GLOB_ONLYDIR) as $dir) {
            $phpFiles = array_merge($phpFiles, MVC::__rglob($dir));
        }

        return $phpFiles;
    }

    /**
     * Find all the routes in the code tree ("/app").
     * The format is similar to Jersey's @Path annotation. To reach
     * /outer/inner1/inner2:
     * - Class must have @Path("/outer")
     * - A method must have @Path("/inner1/inner2")
     * - Optional parameters on the method: @Path("/inner1/inner2/{1}/{2}")
     *      (order is customizable)
     *
     * @return array
     */
    private static function __getRoutes()
    {
        // Find all PHP files
        $phpFiles = MVC::__rglob('app');
        $routeMap = array();
        $routes = array();

        foreach ($phpFiles as $pf) {
            // Get all tokens
            $tokens = token_get_all(file_get_contents($pf));

            // Define an entry in the map array for the current file
            $routeMap[$pf] = new stdClass;
            $routeMap[$pf]->classes = array();

            $className = null;

            // Cycle through the tokens
            while (!empty($tokens)) {

                $tok = array_shift($tokens);

                // If a doccomment is found, parse it
                if ($tok[0] == T_DOC_COMMENT) {

                    $matches = array();

                    // Check for a @Path annotation
                    preg_match_all('#@Path\("([^"]+)"\)#sm', $tok[1], $matches);

                    // If a match is found, process it
                    if (!empty($matches[1])) {

                        // Replace all {<number>}s with named subpatterns
                        $path = preg_replace('/\{(\d+)\}/', '(?P<subp$1>[^\/]+)', $matches[1][0]);
                        $subtok = null;

                        // Find the nearest next class or method
                        do {
                            $subtok = array_shift($tokens);

                            // If a T_CLASS token is found, get the class name
                            // from a nearby token and set the path for the
                            // current "class" object
                            if ($subtok[0] == T_CLASS) {
                                $className = $tokens[1][1];
                                $routeMap[$pf]->classes[$className]->root = $path;
                            }

                            // If a T_CLASS token is found, get the method name
                            // from a nearby token and add a subroute to the
                            // current "class" object
                            if ($subtok[0] == T_FUNCTION) {
                                $routeMap[$pf]->classes[$className]->subroutes[$tokens[1][1]] = $path;
                            }

                        } while (
                            $subtok[0] != T_CLASS &&
                            $subtok[0] != T_FUNCTION &&
                            !empty($tokens)
                        );
                    }
                }
            }
        }

        // Convert the class-centered routing map to a
        // file-centered routing map
        foreach ($routeMap as $file => $route) {

            foreach ($route->classes as $k => $rc) {
                foreach ($rc->subroutes as $sr) {
                    // Trim extra slashes
                    $routes[preg_replace('#/+#', '/', $rc->root . $sr)] = $file;
                }
            }
        }

        return array(
            'files' => $routes,
            'classes' => $routeMap
        );
    }

    public static function __init()
    {
        // Get the routes
        $routes = MVC::__getRoutes();

        // Get the path
        $path = preg_replace('#/$#', '', $_GET['_micromvc_uri']);
        if($path == '')
            $path = '/';

        //var_dump($routes);
        //echo $path;

        $autoload = null;
        $destination = null;

        // Cycle through the file-centered routing map
        // and check if the path matches
        foreach ($routes['files'] as $route => $file) {
            $sr = str_replace('#', '_', $route);
            if (preg_match('#^' . $sr . '$#', $path)) {
                $autoload = $file;
                break;
            }
        }

        // Die if a file to load has not been found
        if ($autoload == null)
            MVC::Fatal(404, 'file not found');

        // Maintain a list of all the annotated classes contained
        // inside the loaded file
        $importedClasses = $routes['classes'][$autoload]->classes;

        require_once($autoload);

        // For each class from the file
        foreach ($importedClasses as $className => $class) {

            // For each subroute in the specified class
            foreach ($class->subroutes as $method => $sr) {

                // Cleanup the subroute path (it's a regexp, comparing strings
                // is not sufficient)
                $srpath = preg_replace('#/$#', '', str_replace('#', '_', $class->root . $sr));
                $srpath = preg_replace('#/+#', '/', $srpath);

                // If the subroute path ($srpath) matches the URI ($path)
                // build a $destination array with class name, method name and parameters
                $matches = array();
                if (preg_match('#^' . $srpath . '$#', $path, $matches)) {
                    $destination = array($className, $method, $matches);
                    break;
                }
            }

            // If I found a destination, stop cycling at all and run it
            if ($destination !== null)
                break;
        }

        if ($destination !== null) {

            // TODO Check that the destination class extends Controller
            //$rc = new ReflectionClass($destination[0]);
            $rm = new ReflectionMethod($destination[0], $destination[1]);
            $params = array();

            // Extract all the function parameters
            for ($i = 1; isset($destination[2]['subp' . $i]); $i++) {
                $params[$i - 1] = $destination[2]['subp' . $i];
            }

            // Die if there are too much or too many parameters
            if (count($params) < $rm->getNumberOfRequiredParameters() ||
                count($params) > $rm->getNumberOfParameters()
            )
                MVC::Fatal(500, 'Wrong number of parameters');

            $object = new $destination[0];
            call_user_func_array(array($object, $destination[1]), $params);
        } else {
            // Never reached
            MVC::Fatal(404, 'file not found');
        }
    }

    public static function Fatal($error, $message)
    {
        die($error . ' ' . $message);
    }
}

// Useless for now
class Controller
{
    public function index()
    {
    }
}

MVC::__init();