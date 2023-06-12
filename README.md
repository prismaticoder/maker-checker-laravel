# prismaticode/maker-checker-laravel

The `prismaticode/maker-checker-laravel` package is a comprehensive Laravel package that provides a flexible and customizable maker-checker system for your application. It allows you to implement an approval workflow for various actions in your application, such as creating, updating, and deleting records. It also allows you to be able to execute random actions. With this package, you can ensure that important actions go through an approval process before they are finalized.

## Features

- **Maker-Checker System**: Implement an approval workflow for critical actions in your application, ensuring that changes are reviewed and approved before being finalized.
- **Flexible Configuration**: Customize the maker-checker settings to fit your application's specific needs. Define models that are allowed to make/check requests, minutes till requests are deemed as expired etc.
- **Event Driven**: The package triggers events throughout the approval workflow, allowing you to hook into these events and perform custom actions or integrations.
- **Logging and Auditing**: Track and log the approval process, including who made the request, who approved it, and when. Gain insights into the history of actions and approvals.

## Installation

You can install the `prismaticode/maker-checker-laravel` package via Composer. Run the following command in your terminal:

```bash
composer require prismaticode/maker-checker-laravel
```

## Configuration

After installing the package, you need to publish the configuration and migration files to customize the maker-checker settings. Run the following command:

```bash
php artisan vendor:publish --provider="prismaticode\MakerChecker\MakerCheckerServiceProvider" --tag="config"
```

This will create a `config/makerchecker.php` file in your application's config as well as a `create_maker_checker_requests_table` migration file in your `database/migrations` folder

## Usage

## Retrieving requests

The package exposes a `MakerCheckerRequest` model that can be queried like any other Laravel model

```php
use Prismaticode\MakerChecker\Models\MakerCheckerRequest

MakerCheckerRequest::all(); // Get all requests
MakerCheckerRequest::status('pending')->get(); // Get all pending requests

```

### Initiating a new request

One of the ways of initiating a new request is by making use of the `MakerChecker` Facade class. Here's an example of how to initiate a request to create a user:

```php
use App\Models\User;
use Primaticode\MakerChecker\Facades\MakerChecker;

//CREATE A USER
MakerChecker::request()
    ->toCreate(User::class, ['name' => 'Tobi David', 'email' => 'johndoe@example.com'])
    ->madeBy(auth()->user())
    ->save();

//UPDATE A USER
MakerChecker::request()
    ->toUpdate($user, ['name' => 'Tommy Ify'])
    ->madeBy(auth()->user())
    ->save()

//DELETE A USER
MakerChecker::request()
    ->toDelete($user)
    ->madeBy(auth()->user())
    ->save()
```

Alternatively, you can choose to include the `MakesRequests` trait in your maker model.

```php
use Illuminate\Database\Eloquent\Model;
use Prismaticode\MakerChecker\Traits\MakesRequests;

class User extends Model
{
    use MakesRequests;

    ...
}
```

With this included, the above requests can now be constructed in the format below

```php
use App\Models\User;

//CREATE A USER
auth()->user()
    ->requestToCreate(User::class, ['name' => 'Tobi David', 'email' => 'johndoe@example.com'])
    ->save();

//UPDATE A USER
auth()->user()
    ->requestToUpdate($user, ['name' => 'Tommy Ify'])
    ->save()

//DELETE A USER
auth()->user()
    ->requestToDelete($user)
    ->save()
```

#### The Executable Request Type

Asides the generic actions of creating, updating and deleting models, it is also possible that you would want to perform miscellaneous requests that do not directly fall into any of this categories e.g making an http call to an external system, combining different actions etc.
It is for this reason that the concept of an executable request was added.

To initiate a new executable request, you first need to create an executable request class that extends the `Primaticode\MakerChecker\Contracts\ExecutableRequest` contract:

```php
use Illuminate\Support\Facades\Http;
use Prismaticode\MakerChecker\Contracts\ExecutableRequest;
use Prismaticode\MakerChecker\Contracts\MakerCheckerRequestInterface;

class InitiatePayment extends ExecutableRequest
{
    public function execute(MakerCheckerRequestInterface $request)
    {
        Http::post('payment-service.com/pay', $request->payload);
    }
}

```

Here, we have an executable request that does something basic when approved: it facilitates a payment based on parameters within the request payload.

To initiate a request to perform this, we use the `requestToExecute()` method:

```php
use App\Executables\InitiatePayment;

auth()->user()
    ->requestToExecute(InitiatePayment::class, ['amount' => '500', 'currency' => 'NGN', 'to_account' => 'john@example.com'])
    ->save();
```

When this request is approved, a call will be made to the `execute()` method of the executable class to facilitate the action specified.

### Approving/Rejecting a Request

To approve or reject a Maker-Checker request, you can use the MakerChecker facade as well. Here's an example of how to approve a request:

```php
use Primaticode\MakerChecker\Facades\MakerChecker;

MakerChecker::approve($request, auth()->user(), $reason);
```

In the example above, we use the `approve()` method of the `MakerChecker` facade to approve a request. We pass an instance of the request model and the user who is approving the request.

Similarly, you can use the `reject()` method to reject a request:

```php
use Primaticode\MakerChecker\Facades\MakerChecker;

MakerChecker::approve($request, auth()->user()), $reason;
```

Just like the `MakesRequests` trait, this package also provides a `ChecksRequests` traits that can be included in the checking model:

```php
use Illuminate\Database\Eloquent\Model;
use Prismaticode\MakerChecker\Traits\ChecksRequests;

class Admin extends Model
{
    use ChecksRequests;

    ...
}
```

With the above done, approval and rejection can now look like this:

```php
use Primaticode\MakerChecker\Facades\MakerChecker;

//Approve a request
auth()->user()->approve($request, $reason);

//Reject a request
auth()->user()->reject($request, $reason);
```

### Event Listeners and User-Defined Callbacks

The Maker-Checker package fires events at different stages of the request lifecycle, allowing you to listen to these events and perform additional actions as needed. In addition to event listeners, you can also pass in user-defined callbacks when initiating a request to specify actions to be performed after/before the request is approved or rejected.

#### Listening to Events

You can listen to the Maker-Checker events by registering event listeners in your application. Here's an example of how to listen to the events provided by the package

```php
use Primaticode\MakerChecker\Events\RequestApproved;
use Primaticode\MakerChecker\Events\RequestFailed;
use Primaticode\MakerChecker\Events\RequestInitiated;
use Primaticode\MakerChecker\Events\RequestRejected;
use Primaticode\MakerChecker\Listeners\SendEmailNotification;

Event::listen(RequestApproved::class, function (RequestApproved $event) {
    $request = $event->request; // Get the request instance
    // Perform additional actions based on the approved request
});

Event::listen(RequestRejected::class, function (RequestRejected $event) {
    $request = $event->request; // Get the request instance
    // Perform additional actions based on the rejected request
});

Event::listen(RequestInitiated::class, function (RequestInitiated $event) {
    $request = $event->request; // Get the request instance
    // Perform additional actions based on the initiated request
});

Event::listen(RequestFailed::class, function (RequestFailed $event) {
    $request = $event->request; // Get the request instance
    $exception = $event->exception; //Get the exception encountered
    // Perform additional actions based on the failed request
});
```

In the example above, we use the `Event::listen()` method to register a listener for the four events exposed by the package. The event listener receives an instance of the event and can then proceed to take actions on the request as passed in the event.

#### User-Defined Callbacks

When initiating a Maker-Checker request, you can also pass in user-defined callbacks to specify actions to be performed after/before the request is approved or rejected. Here's an example of how to use user-defined callbacks:

```php
use App\Models\User;
use Primaticode\MakerChecker\Models\MakerCheckerRequest;
use Throwable

auth()->user()
    ->requestToCreate(User::class, ['name' => 'Tobi David'])
    ->beforeApproval(function (MakerCheckerRequest $request) {
        // Perform actions before the request is approved (if this action fails, the request is marked as failed)
    })
    ->beforeRejection(function (MakerCheckerRequest $request) {
        // Perform actions before the request is rejected (if this action fails, the request is marked as failed)
    })
    ->afterApproval(function (MakerCheckerRequest $request) {
        // Perform actions after the request is approved
    })
    ->afterRejection(function (MakerCheckerRequest $request) {
        // Perform actions after the request is rejected
    })
    ->onFailure(function (MakerCheckerRequest $request, Throwable $exception) {
        // Perform action when the request fails
    })
    ->save();
```

These five different methods can be chained to determine actions to happen during different events in the request lifecycle.

That's it! You're now equipped with event listeners and user-defined callbacks to extend the functionality of the Maker-Checker package in your Laravel application.

## Credits

This package draws some inspiration from the excellent [`spatie/laravel-activitylog`](https://github.com/spatie/laravel-activitylog) package by Spatie. I'm thankful for their constant work in developing the Laravel ecosystem.

## Contributing

I'm happy to receive contributions from the community to enhance and improve this package. Feel free to submit bug reports, feature requests, or pull requests here.

## License

The `prismaticode/maker-checker-laravel` package is open-source software licensed under the MIT license. Please refer to the LICENSE file for more information.
