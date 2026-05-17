<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Models\Product;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ShopAvailabilityCommand extends Command
{
    protected $signature = 'shop:stocks:availability
                            {product : Product ID, slug, SKU, or partial name}
                            {--from= : First day of the calendar (YYYY-MM-DD, defaults to start of current month)}
                            {--to= : Last day of the calendar (YYYY-MM-DD, defaults to end of the --from month)}
                            {--day= : Show a detail timeline for a single day instead of the calendar (YYYY-MM-DD)}';

    protected $description = 'Render an ASCII availability calendar for a product (pool, loanable, booking, or simple)';

    private const CELL_WIDTH = 6;

    public function handle(): int
    {
        $product = $this->resolveProduct((string) $this->argument('product'));
        if (! $product) {
            $this->error("No product matched '{$this->argument('product')}'.");
            return self::FAILURE;
        }

        if ($this->option('day')) {
            return $this->renderDayDetail($product, Carbon::parse((string) $this->option('day'))->startOfDay());
        }

        $rangeStart = $this->option('from')
            ? Carbon::parse((string) $this->option('from'))->startOfDay()
            : Carbon::now()->startOfMonth();
        $rangeEnd = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))->endOfDay()
            : $rangeStart->copy()->endOfMonth();

        // Snap to a Mon→Sun grid so weeks line up.
        $gridStart = $rangeStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $rangeEnd->copy()->endOfWeek(Carbon::SUNDAY);

        $calendar = $product->calendarAvailability($gridStart, $gridEnd);

        $this->renderProductHeader($product);
        $this->renderSummaryCounters($product);
        $this->renderMonthLabel($rangeStart);
        $this->renderLegend();
        $this->renderCalendarGrid($calendar['dates'], $gridStart, $gridEnd, $rangeStart);
        $this->renderFooterStats($calendar);

        return self::SUCCESS;
    }

    private function resolveProduct(string $identifier): ?Product
    {
        $model = config('shop.models.product', Product::class);

        return $model::query()
            ->where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->orWhere('sku', $identifier)
            ->orWhere('name', 'like', "%{$identifier}%")
            ->first();
    }

    private function renderProductHeader(Product $product): void
    {
        $type = $product->type instanceof \BackedEnum ? $product->type->value : (string) ($product->type ?? '—');
        $sku = $product->sku ?: '—';

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>'.$product->name.'</>');
        $this->line('  <fg=gray>type:</> '.$type.'   <fg=gray>sku:</> '.$sku.'   <fg=gray>id:</> '.$product->id);
        $this->newLine();
    }

    private function renderSummaryCounters(Product $product): void
    {
        $available = $product->getAvailableStock();
        $currentClaims = $product->getCurrentlyClaimedStock();
        $futureClaims = $product->getFutureClaimedStock();
        $activeAndPlanned = $product->getActiveAndPlannedClaimedStock();

        $this->line(sprintf(
            '  <fg=green;options=bold>Available %s</>   <fg=yellow>Currently claimed %d</>   <fg=blue>Future claims %d</>   <fg=magenta>Active & planned %d</>',
            $this->infinityOr($available),
            $currentClaims,
            $futureClaims,
            $activeAndPlanned,
        ));
        $this->newLine();
    }

    private function renderMonthLabel(Carbon $focus): void
    {
        $this->line('  <options=bold>'.$focus->format('F Y').'</>');
        $this->newLine();
    }

    private function renderLegend(): void
    {
        $this->line(
            '  <fg=green>━━━━━</> Full availability   '.
            '<fg=yellow>━━━━━</> Partial   '.
            '<fg=red>━━━━━</> No stock'
        );
        $this->newLine();
    }

    /**
     * @param  array<string, array{min: int, max: int}>  $days
     */
    private function renderCalendarGrid(array $days, Carbon $gridStart, Carbon $gridEnd, Carbon $focus): void
    {
        $w = self::CELL_WIDTH;
        $hr = '┌'.implode('┬', array_fill(0, 7, str_repeat('─', $w))).'┐';
        $midRule = '├'.implode('┼', array_fill(0, 7, str_repeat('─', $w))).'┤';
        $bot = '└'.implode('┴', array_fill(0, 7, str_repeat('─', $w))).'┘';

        $this->line('  '.$hr);
        $this->line('  │'.collect(['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'])
            ->map(fn ($h) => $this->pad($h, $w))->implode('│').'│');
        $this->line('  '.$midRule);

        $cursor = $gridStart->copy();
        $weeks = [];
        while ($cursor <= $gridEnd) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = $cursor->copy();
                $cursor->addDay();
            }
            $weeks[] = $week;
        }

        foreach ($weeks as $i => $week) {
            $dayLine = '│';
            $barLine = '│';
            $statLine = '│';

            foreach ($week as $day) {
                $key = $day->toDateString();
                $cell = $days[$key] ?? ['min' => 0, 'max' => 0];
                $status = $this->statusFor($cell);
                $color = $this->colorFor($status);
                $inMonth = $day->month === $focus->month;
                $isToday = $day->isToday();

                $numText = (string) $day->day;
                if ($isToday) {
                    $numText = '['.$numText.']';
                }
                $numCell = $this->pad($numText, $w);
                if ($isToday) {
                    $numCell = "<fg=cyan;options=bold>$numCell</>";
                } elseif (! $inMonth) {
                    $numCell = "<fg=gray>$numCell</>";
                }
                $dayLine .= $numCell.'│';

                $bar = str_repeat('━', $w - 2);
                $barCell = ' '."<fg=$color;options=bold>$bar</>".' ';
                $barLine .= $barCell.'│';

                $stat = $this->infinityOr($cell['min']).'-'.$this->infinityOr($cell['max']);
                $statCell = $this->pad($stat, $w);
                if (! $inMonth) {
                    $statCell = "<fg=gray>$statCell</>";
                } else {
                    $statCell = "<fg=$color>$statCell</>";
                }
                $statLine .= $statCell.'│';
            }

            $this->line('  '.$dayLine);
            $this->line('  '.$barLine);
            $this->line('  '.$statLine);
            $this->line('  '.($i === count($weeks) - 1 ? $bot : $midRule));
        }
        $this->newLine();
    }

    /**
     * @param  array{max_available: int, min_available: int, dates: array<string, array{min: int, max: int}>}  $calendar
     */
    private function renderFooterStats(array $calendar): void
    {
        $days = $calendar['dates'];
        $maxAvailable = $this->infinityOr($calendar['max_available']);
        $minAvailable = $this->infinityOr($calendar['min_available']);
        $daysTracked = (string) count($days);
        $lowStockDays = (string) count(array_filter(
            $days,
            fn (array $d) => $d['min'] === 0 || $d['max'] === 0,
        ));

        $boxes = [
            ['MAX AVAILABLE', $maxAvailable, 'cyan'],
            ['MIN AVAILABLE', $minAvailable, 'red'],
            ['DAYS TRACKED', $daysTracked, 'gray'],
            ['LOW STOCK DAYS', $lowStockDays, 'yellow'],
        ];

        $boxWidth = 16;
        $top = '┌'.implode('┬', array_fill(0, count($boxes), str_repeat('─', $boxWidth))).'┐';
        $bot = '└'.implode('┴', array_fill(0, count($boxes), str_repeat('─', $boxWidth))).'┘';

        $labelLine = '│';
        $valueLine = '│';
        foreach ($boxes as [$label, $value, $color]) {
            $labelLine .= "<fg=$color>".$this->pad($label, $boxWidth).'</>│';
            $valueLine .= "<fg=$color;options=bold>".$this->pad($value, $boxWidth).'</>│';
        }

        $this->line('  '.$top);
        $this->line('  '.$labelLine);
        $this->line('  '.$valueLine);
        $this->line('  '.$bot);
        $this->newLine();
    }

    private function renderDayDetail(Product $product, Carbon $day): int
    {
        $timeline = $product->dayAvailability($day);

        $this->renderProductHeader($product);

        $this->line('  <fg=cyan;options=bold>'.$day->format('l, F j, Y').'</>');
        $this->newLine();

        if ($timeline === PHP_INT_MAX) {
            $this->line('  <fg=green;options=bold>Unlimited availability all day.</> <fg=gray>(manage_stock = false)</>');
            $this->newLine();
            return self::SUCCESS;
        }

        // $timeline is array<string HH:MM, int available>
        $this->line('  <fg=gray>Stock changes throughout the day:</>');
        $this->newLine();

        $rows = [];
        $previous = null;
        foreach ($timeline as $time => $available) {
            // Skip redundant rows where nothing actually changed since the last event.
            if ($previous !== null && $available === $previous) {
                continue;
            }
            $previous = $available;
            $rows[] = [$time, $available];
        }

        $timeWidth = 8;
        $availWidth = 14;
        $noteWidth = 22;
        $top = '┌'.str_repeat('─', $timeWidth).'┬'.str_repeat('─', $availWidth).'┬'.str_repeat('─', $noteWidth).'┐';
        $mid = '├'.str_repeat('─', $timeWidth).'┼'.str_repeat('─', $availWidth).'┼'.str_repeat('─', $noteWidth).'┤';
        $bot = '└'.str_repeat('─', $timeWidth).'┴'.str_repeat('─', $availWidth).'┴'.str_repeat('─', $noteWidth).'┘';

        $this->line('  '.$top);
        $this->line('  │'.$this->pad('TIME', $timeWidth).'│'.$this->pad('AVAILABLE', $availWidth).'│'.$this->pad('NOTE', $noteWidth).'│');
        $this->line('  '.$mid);

        foreach ($rows as [$time, $available]) {
            $unitWord = $available === 1 ? 'unit' : 'units';
            $availText = $available.' '.$unitWord;
            $note = $available === 0 ? '⚠ Out of stock' : '';
            $color = $available === 0 ? 'red' : 'green';

            $timeCell = $this->pad($time, $timeWidth);
            $availCell = "<fg=$color;options=bold>".$this->pad($availText, $availWidth).'</>';
            $noteCell = "<fg=$color>".$this->pad($note, $noteWidth).'</>';

            $this->line('  │'.$timeCell.'│'.$availCell.'│'.$noteCell.'│');
        }

        $this->line('  '.$bot);
        $this->newLine();

        $values = array_values($timeline);
        $this->line(sprintf(
            '  <fg=cyan>MIN STOCK</> %d   <fg=cyan>MAX STOCK</> %d   <fg=gray>EVENTS</> %d',
            min($values),
            max($values),
            count($rows),
        ));
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  array{min: int, max: int}  $cell
     */
    private function statusFor(array $cell): string
    {
        if ($cell['max'] <= 0) {
            return 'none';
        }
        if ($cell['min'] <= 0) {
            return 'partial';
        }
        return 'full';
    }

    private function colorFor(string $status): string
    {
        return match ($status) {
            'full' => 'green',
            'partial' => 'yellow',
            default => 'red',
        };
    }

    private function infinityOr(int $value): string
    {
        return $value === PHP_INT_MAX ? '∞' : (string) $value;
    }

    private function pad(string $value, int $width): string
    {
        $len = mb_strlen($value);
        if ($len >= $width) {
            return mb_substr($value, 0, $width);
        }
        $extra = $width - $len;
        $left = (int) floor($extra / 2);
        $right = $extra - $left;

        return str_repeat(' ', $left).$value.str_repeat(' ', $right);
    }
}
