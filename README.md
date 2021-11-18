# Router Extension

This extension adds content routing features to [Rose](https://github.com/rsthn/rose-core).

```sh
composer require rsthn/rose-ext-router
```

After installation ensure Router is the primary service in your system by editing the `Gateway` section of your `system.conf` file and set `service=router`. Additionally unless you want to use `index.php/my-route/` we recommend you enable URL rewriting to make pretty URLs. If using Apache or compatible an `.htaccess` like the following will do:

```
RewriteEngine On
RewriteBase /my-project/

RewriteCond %{SCRIPT_FILENAME} !-f
RewriteCond %{SCRIPT_FILENAME} !-d
RewriteRule ^(.*)$ index.php/$1 [L,QSA]
```

<br/>&nbsp;
# Default Operation

Router will use the relative path provided by `Gateway` (i.e. when using something like `index.php/home` the relative path is `/home`) and return the appropriate content using the following rules (in order of precedence, whichever is satisfied first):

- User is authenticated<sup>1</sup> and there is a `rcore/content/home/private.html` file, it will be loaded, evaluated with `Expr` and sent to the client.

- User is not authenticated and there is a `rcore/content/home/public.html` file, it will be loaded, evaluated with `Expr` and sent to the client.

- When a `rcore/content/home/index.html` file exists, it will be loaded, evaluated with `Expr` and sent to the client.

- User is not authenticated and there is only a `rcore/content/home/private.html` then Router will redirect to `/login`.

- Router will redirect to `/404`.

<small><sup>1</sup>The condition "authenticated user" is determined by checking if the `user` field of the current session (if any) is set to a non-null value (as set by [Sentinel](https://github.com/rsthn/rose-core)).</small>

<br/>&nbsp;
# Routing to Services or URLs

When a certain route (i.e. `/api`) should pass control to another Rose service (i.e. `wind`) or redirect to a URL you can use the `Router` section of the `system.conf` file to achieve this, by providing "route=service_name" pairs (where route is a delimiter-less regex), such as:

```ini
[Router]
/api = service:wind
/details/([0-9]+) = service:wind/?f=get-details&id=(1)
/external-page = location:https://example.com/contact-us/
/([0-9]+)/home = home/(1)
```

## Service Redirection

- Using the `service:` prefix in the value will cause an internal redirection to the specified service.
	- i.e. `/api = service:wind` will redirect to service `wind`.

- Any path provided in the value will be set as the new relative path.
	- i.e. `/api = service:wind/details` will redirect to service `wind` setting relative path to `/details`.

- Any path provided by the client after the route base will be appended to the relative path.
	- i.e. `/api = service:wind/details` but loaded from client as `/api/users` will cause redirection to service `wind` with relative path `/details/users`.

- Any query parameters in the value will be merged into the current request parameters.
	- i.e. `/api = service:wind/details?b=B` and loaded from client as `/api/users?a=A` will cause redirection to service `wind` with relative path `/details/users` and request parameters `a=A&b=B`.

## Location Redirection

- Using the `location:` prefix in the value will cause the `location` header to be set in the response HTTP headers and an immediate execution termination.
	- i.e. `/contact = location:https://example.com/` will redirect to URL `https://example.com/`.

- Any path provided by the client after the route base will be ignored.
	- i.e. `/contact = location:https://example.com/` and loaded from client as `/contact/sub-path/` will redirect to `https://example.com/`.

- Any query parameters specified by the client will be ignored.

	- i.e. `/contact = location:https://example.com/?a=A` and loaded from client as `/contact/sub-path/?b=B` will redirect to `https://example.com/?a=A`.

## Notes

The value of routes is evaluated using `Expr`, therefore any formating can be made if needed, and variables from the gateway's request parameter map can be accessed as usual via `(gateway.request.<name>)`.
