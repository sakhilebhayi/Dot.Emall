<x-operator-layout>
    <x-slot:title>Merchandising</x-slot:title>
    <x-slot:heading>Merchandising</x-slot:heading>
    <x-slot:lead>How the shop is arranged and how its search understands people. Everything here is editorial and labelled as ours: nothing is for sale and nothing changes a ranking for money.</x-slot:lead>

    <ul class="op-hub">
        <li><a href="{{ route('operator.collections.index') }}"><strong>Collections</strong><span>Hand-picked or rule-based groups of listings, with start and end dates. A rule is run by the same search the shop uses, so it never shows something that cannot be bought.</span></a></li>
        <li><a href="{{ route('operator.search.queries') }}"><strong>What people searched</strong><span>Searches that found nothing, with how many people ran them, and the popular searches. Start a synonym from any of them.</span></a></li>
        <li><a href="{{ route('operator.search.synonyms') }}"><strong>Synonyms</strong><span>Words that mean the same ("takkies", "sneakers"), with a test box that shows how a search is understood.</span></a></li>
        <li><a href="{{ route('operator.search.complements') }}"><strong>Often needed with</strong><span>Editorial rules such as "laptops: bags and mice". Shown to shoppers as our suggestion, not as something measured.</span></a></li>
        <li><a href="{{ route('operator.catalogue.categories') }}"><strong>Categories</strong><span>The category tree: names, order, slugs, parents, hiding, and the attributes sellers fill in per category.</span></a></li>
        <li><a href="{{ route('operator.catalogue.featured') }}"><strong>Featured stores</strong><span>Your selection of stores to feature. Labelled as the editors' pick wherever it appears.</span></a></li>
    </ul>
</x-operator-layout>
