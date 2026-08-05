import { useTranslate } from '@/hooks/use-translate';
import { spokenDuration } from '@/lib/duration';
import { cn } from '@/lib/utils';

export interface CalendarDay {
    date: string;
    seconds: number;
    /** 0 for a day nobody worked, up to 4 for a full one. */
    level: number;
    /** A day that has not happened yet, in the week we are in. */
    future: boolean;
}

export interface HoursCalendar {
    weeks: CalendarDay[][];
    from: string;
    until: string;
}

/**
 * How dark a square is at each level.
 *
 * Mixed from the workspace's own accent rather than from a fixed palette, so
 * the chart is the colour the workspace chose — the same --primary every button
 * in the application resolves through. color-mix with transparent rather than
 * with white: on a dark background, mixing towards white would make a quiet
 * week glow, while mixing towards nothing lets the page behind it show through
 * in both schemes.
 *
 * Four steps and not more. The eye cannot rank six shades of one hue, and the
 * exact number is on the tooltip for anybody who wants it.
 */
const LEVEL_MIX = ['0%', '25%', '45%', '70%', '100%'] as const;

/**
 * The last half year of working days, a column per week.
 *
 * Read the way GitHub taught everybody to read this shape: time runs left to
 * right, the week runs top to bottom, and darker means more. What it adds over
 * the bars below it is the thing bars cannot show — a rhythm. Four dark weeks
 * and a pale one is a holiday; a pale Friday column all year is a four-day
 * week.
 *
 * Clicking a column opens that week below, which is what makes it a map rather
 * than a decoration.
 */
export function HoursCalendar({
    calendar,
    weeksBack,
    onSelectWeek,
    locale,
}: {
    calendar: HoursCalendar;
    /** Which column is being read below, counted back from the last one. */
    weeksBack: number;
    onSelectWeek: (weeksBack: number) => void;
    locale: string;
}) {
    const { t } = useTranslate();

    const lastIndex = calendar.weeks.length - 1;

    /*
     * The Monday a column stands for, as somebody reads it. Parsed with a time
     * on it rather than bare: a plain date string is read as midnight UTC,
     * which is the day before for every reader west of Greenwich.
     */
    const readable = (date: string) =>
        new Intl.DateTimeFormat(locale, {
            day: 'numeric',
            month: 'short',
        }).format(new Date(`${date}T12:00:00`));

    return (
        <div className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold">
                    {t('timeclock.calendar.title')}
                </h2>
                <span className="text-xs text-muted-foreground">
                    {readable(calendar.from)} – {readable(calendar.until)}
                </span>
            </div>

            {/*
                Scrolls sideways rather than shrinking the squares: half a year
                is wider than a narrow window, and a grid that squeezed itself
                to fit would end up a smear of colour with no days in it.

                Padded on all four sides and pulled back out again with the
                negative margin. Asking for overflow on one axis makes the
                browser clip the other one too — that is what the spec says a
                non-visible overflow does to its neighbour — so without the room
                the top row and the ring around the chosen week were sliced off
                at the edge of the box.
            */}
            <div className="-m-1 overflow-x-auto p-1">
                <div className="flex w-max gap-[3px]">
                    {calendar.weeks.map((week, index) => {
                        const back = lastIndex - index;
                        const worked = week.reduce(
                            (total, day) => total + day.seconds,
                            0,
                        );

                        return (
                            <button
                                key={week[0].date}
                                type="button"
                                onClick={() => onSelectWeek(back)}
                                aria-label={t('timeclock.week_of', {
                                    date: readable(week[0].date),
                                })}
                                aria-current={
                                    back === weeksBack ? 'true' : undefined
                                }
                                title={`${t('timeclock.week_of', { date: readable(week[0].date) })} — ${spokenDuration(worked)}`}
                                /*
                                    The week being read is ringed rather than
                                    filled. A filled column was the same grey as
                                    a day nobody worked, which made it read as
                                    one tall bar with the squares swallowed
                                    inside it — the outline says "this one"
                                    without taking part in the colour scale.
                                */
                                className={cn(
                                    'flex shrink-0 flex-col gap-[3px] rounded-[3px] p-[3px] ring-1 transition-colors',
                                    back === weeksBack
                                        ? 'ring-primary/60'
                                        : 'ring-transparent hover:ring-border',
                                )}
                            >
                                {week.map((day) => (
                                    <span
                                        key={day.date}
                                        className={cn(
                                            'size-3 rounded-[3px]',
                                            // A day still to come is left blank
                                            // rather than drawn as an empty
                                            // one: nothing was worked and
                                            // nothing was skipped either.
                                            day.future
                                                ? 'bg-transparent'
                                                : day.level === 0 &&
                                                      'bg-muted-foreground/15',
                                        )}
                                        style={
                                            day.future || day.level === 0
                                                ? undefined
                                                : {
                                                      backgroundColor: `color-mix(in oklab, var(--primary) ${LEVEL_MIX[day.level]}, transparent)`,
                                                  }
                                        }
                                    />
                                ))}
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="flex items-center justify-end gap-1.5 text-xs text-muted-foreground">
                <span>{t('timeclock.calendar.less')}</span>
                {LEVEL_MIX.map((mix, level) => (
                    <span
                        key={mix}
                        className={cn(
                            'size-3 rounded-[3px]',
                            level === 0 && 'bg-muted-foreground/15',
                        )}
                        style={
                            level === 0
                                ? undefined
                                : {
                                      backgroundColor: `color-mix(in oklab, var(--primary) ${mix}, transparent)`,
                                  }
                        }
                    />
                ))}
                <span>{t('timeclock.calendar.more')}</span>
            </div>
        </div>
    );
}
