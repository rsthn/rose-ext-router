# Router Extension

This extension adds content router features to [Rose](https://github.com/rsthn/rose-core).

# Installation

```sh
composer require rsthn/rose-ext-router
```

After installation ensure Router is the primary service by editing the `Gateway` section of your `system.conf` file and set `service=router`.

# Operation

Router will detect the relative path used with the `index.php` file (i.e. when using something like `index.php/home` the relative path is `/home`) and return the appropriate content using the following rules (in order of precedence, whichever is satisfied first):

- User is authenticated and there is a `resources/content/home/private.html` file, it will be loaded, evaluated with `Expr` and returned to the client.

- User is not authenticated and there is a `resources/content/home/public.html` file, it will be loaded, evaluated with `Expr` and returned to the client.

- There is a `resources/content/home/index.html` file, it will be loaded, evaluated with `Expr` and returned to the client.

- User is not authenticated and there is only a `resources/content/home/private.html'` then Router will redirect to `/login`.

- Router will redirect to `/404`.
