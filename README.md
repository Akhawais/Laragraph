# LaraGraph

[WIP] An elegant wrapper to `webonyx/graphql-php` that uses PHP 8 attributes.

# Installation

> Requirements: PHP 8

`composer require scriptle/laragraph`

# Usage

Publish the config file:

`php artisan vendor:publish --provider=\Scriptle\Laragraph\LaragraphServiceProvider`

Amend the schemas to your preference.

## Folder Structure

- Mutation roots live in `app/GraphQL/Mutations`
- Query roots live in `app/GraphQL/Queries`
- Types live in `app/GraphQL/Types`

`app/Models` is also traversed for models with GraphQL `Type` attributes present.

## Schema

Schemas are defined in `config/laragraph.php` under the `schemas` key.

```php 
return [
    'prefix' => 'gql',
    'schemas' => [
        'v1' => [
            'schema' => [
                'query' => 'V1Query',
                'mutation' => 'V1Mutation',
            ],
            'middleware' => null,
        ],
        /* 'v2' => [
        *       'schema' => [
        *           'query' => 'V2Query',
        *           'mutation' => 'V2Mutation',
        *       ],
        *       'middleware' => 'throttle:60,1',
        *   ]
        */
    ]
];
```
The prefix (`gql`) is prepended to all schemas. The schema key is the URL. So for `v1`, the full URL is `/gql/v1`.

Within the `schema` key you define your GraphQL schema as usual, referencing the root types by string.

You can add custom `middleware` with that key, such as an entire schema limited to admin users.

Then, you must add your root types as you defined them, like so:

```php
namespace App\GraphQL\Queries;
use Scriptle\Laragraph\Type;
use App\Models\User;
...
#[Type('V1Query', 'Root queries for v1')]
class V1Query {
    #[Field('users', '[User]!', 'Lists all users.')]
    public function users() {
        return User::all();
    }
    
    // More on this later
    #[Field('lookupUser("User ID" id: String, "User Email" email: String)', 'User', 'Look up a user by ID or email.')]
    public function lookupUser($args) {
        // More on this later
        return User::where($args)->first();
    }
}
```

## Attributes

On the **class**, add the following attribute:
```php
namespace App\Models\User;
use Scriptle\Laragraph\Type;
...
#[Type]
class User extends Authenticatable {
    ...
}
```

`#[Type]` on its own will use the class name as the GraphQL type name.

`#[Type('Person')]` will use `Person` as the GraphQL type name.

`#[Type('User', 'A user in the database.')]` will use `User` as the GraphQL type name, plus a GraphQL introspection descriptor for this type.

### Defining Fields

To define simple fields returned through Eloquent, you can simply attach them with the `Type` attribute:

```php
namespace App\Models\User;
use Scriptle\Laragraph\Type;
use Scriptle\Laragraph\Field;
...
#[
    Type('User', 'A user in the database.'),
    Field('id', 'String!', 'User ID'),
    Field('first_name', 'String!', "User's first name"),
    Field('last_name', 'String!', "User's last name"),
    Field('roles', '[Role]!', 'Array of user roles'),
]
class User extends Authenticatable {
    ...
}
```
Note: Eloquent relationships are automatically supported this way.

### Fields with a custom resolver

If your field is not database-based, or needs to be passed through a callback you can use this feature.

EITHER:
```php
namespace App\Models\User;
use Scriptle\Laragraph\Type;
use Scriptle\Laragraph\Field;
...
#[
    Type('User', 'A user in the database.'),
    Field('id', 'String!', 'User ID'),
    Field('first_name', 'String!', "User's first name"),
    Field('last_name', 'String!', "User's last name"),
    Field('roles', '[Role]!', 'Array of user roles'),
]
class User extends Authenticatable {
    ...
    public function resolveLastNameField()
    {
        return strtoupper($this->attributes['last_name']);
    }
}
```
Format: `resolve` + (StudlyCase) `LastName` + `Field` = `resolveLastNameField`


OR

```php
namespace App\Models\User;
use Scriptle\Laragraph\Type;
use Scriptle\Laragraph\Field;
use Scriptle\Laragraph\ResolvesFor;
...
#[
    Type('User', 'A user in the database.'),
    Field('id', 'String!', 'User ID'),
    Field('first_name', 'String!', "User's first name"),
    Field('last_name', 'String!', "User's last name"),
    Field('roles', '[Role]!', 'Array of user roles'),
]
class User extends Authenticatable {
    ...
    #[ResolvesFor('last_name')]
    public function getLastNameAttribute()
    {
        return strtoupper($this->attributes['last_name']);
    }
}
```
Function can be named anything as long as it has the `ResolvesFor` attribute. This hooks in nicely with Laravel's custom getters.

OR LASTLY:

```php
namespace App\Models\User;
use Scriptle\Laragraph\Type;
use Scriptle\Laragraph\Field;
...
#[
    Type('User', 'A user in the database.'),
    Field('id', 'String!', 'User ID'),
    Field('first_name', 'String!', "User's first name"),
    Field('roles', '[Role]!', 'Array of user roles'),
]
class User extends Authenticatable {
    ...
    #[Field('last_name', 'String!', "User's last name")]
    public function anything()
    {
        return strtoupper($this->attributes['last_name']);
    }
}
```
Move the Field attribute down to the function instead, if you prefer this style.

### Argument-ed Fields

Some fields, especially in queries, need arguments. The syntax is direct GraphQL definition syntax:

`#[Field('lookupUser("User ID" id: String!)', 'User', 'Look up a user by ID.')]`

This defines a `lookupUser` field with 1 argument, `id` of type `String!` (with ! so it must be non-null) and with introspection description of `User ID`.

The same can be done for mutations or querying fields on types.

## Custom Types

Custom Types can be defined like the User model above, and can be placed in the `app/GraphQL/Types` folder to keep them separate from your models. This is useful for mutation responses, or other complex response structures.

Types are referenced by their type name (as registered by the `#[Type]` attribute), just with regular GraphQL. **There is no need to create a variable or reference classes directly!**

# Caching

The `config/laragraph.php` will be cached along with Laravel's regular config caching (`php artisan config:cache` || `php artisan config:clear`)

You can also cache Laragraph's Type mappings to avoid processing these on each request. Use `php artisan gql:cache` to cache, and `php artisan gql:clear` to remove.
