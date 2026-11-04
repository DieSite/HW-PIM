        <header class="sl-bar">
            <h1><a href="{{ route('admin.simple-stock.index') }}" style="text-decoration: none;">Voorraad zoeken</a></h1>

            <nav>
                <span>{{ auth()->guard('admin')->user()->name }}</span>

                @if ($canUsePim)
                    <a href="{{ route('admin.dashboard.index') }}">Volledige PIM</a>
                @endif

                <form method="POST" action="{{ route('admin.session.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit">Uitloggen</button>
                </form>
            </nav>
        </header>
