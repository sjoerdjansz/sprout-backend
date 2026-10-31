<x-mail::message>
# Hi {{ $name }},

You asked to sign in to Sprout with this email address from now on. Tap the button to confirm it.

<x-mail::button :url="$confirmUrl">
Confirm my new email
</x-mail::button>

The link works once and expires in {{ $lifetimeInMinutes }} minutes. Until then, you sign in with your old address.

Didn't ask for this? You can ignore this email. Nothing changes without the link.

Happy learning,<br>
Sprout
</x-mail::message>
