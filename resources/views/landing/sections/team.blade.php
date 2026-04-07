<section id="team" class="py-20 px-6">
    <div class="max-w-6xl mx-auto">
        <h2 class="text-3xl md:text-4xl font-bold text-primary text-center mb-6">
            {{ $locale === 'ar' ? 'فريق العمل' : 'Our Team' }}
        </h2>
        <div class="w-20 h-1 bg-secondary mx-auto mb-12 rounded"></div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
            @foreach($team as $member)
                <div class="text-center">
                    <div class="w-24 h-24 bg-primary/10 rounded-full mx-auto mb-4 flex items-center justify-center">
                        <span class="text-primary text-2xl font-bold">
                            {{ mb_substr($member['name'], 0, 1) }}
                        </span>
                    </div>
                    <h3 class="text-lg font-bold text-gray-800">{{ $member['name'] }}</h3>
                    <p class="text-secondary text-sm">{{ $member['role'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
