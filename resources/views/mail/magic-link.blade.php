<x-mail::message>
# Hi {{ $name }},

Tap the button below and you're in. No password needed.

<x-mail::button :url="$magicLinkUrl">
Sign in to Sprout
</x-mail::button>

The link works once and expires in {{ $lifetimeInMinutes }} minutes.

Didn't ask for this? You can ignore this email. Nobody can sign in without the link.

Happy learning,<br>
Sprout
</x-mail::message>
