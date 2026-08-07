import * as React from "react"
import { ScrollArea as ScrollAreaPrimitive } from "radix-ui"

import { cn } from "@/lib/utils"

function ScrollArea({
  className,
  children,
  ...props
}: React.ComponentProps<typeof ScrollAreaPrimitive.Root>) {
  return (
    <ScrollAreaPrimitive.Root
      data-slot="scroll-area"
      className={cn("relative", className)}
      {...props}
    >
      {/*
        Radix sets this element's overflow from state the scrollbar only settles
        once its effects have run: overflowY is "scroll" while a scrollbar is
        enabled and "hidden" while it is not. Effects do not run on the server,
        so the two renders cannot agree, and with SSR on (see config/inertia.php)
        every ScrollArea on the page reports a hydration mismatch.

        Suppressed rather than worked around. The mismatch is one style property
        that React re-renders correctly the moment Radix's effects run, so
        nothing is left wrong on screen — what the warning costs is a console
        full of noise that hides the mismatches worth reading.
      */}
      {/*
        `[&>div]:block!` overrules Radix. It wraps whatever you pass in a div of
        its own carrying `display: table`, so that a viewport can be scrolled
        sideways past content wider than itself. Nothing here offers a
        horizontal scrollbar, and that shape costs us on a tablet: a table's
        width is max(min-content, ...), never less than its own content, and
        WebKit does not discount an `overflow-x-auto` descendant from that
        minimum the way the spec asks. So a table set to `min-w-3xl` inside its
        own scroll box widened that wrapper instead of scrolling in place, and
        the settings page ran off the right of an iPad. A plain block cannot
        outgrow the viewport, which leaves the inner box to scroll.
      */}
      <ScrollAreaPrimitive.Viewport
        suppressHydrationWarning
        data-slot="scroll-area-viewport"
        className="size-full rounded-[inherit] transition-[color,box-shadow] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-1 [&>div]:block!"
      >
        {children}
      </ScrollAreaPrimitive.Viewport>
      <ScrollBar />
      <ScrollAreaPrimitive.Corner />
    </ScrollAreaPrimitive.Root>
  )
}

function ScrollBar({
  className,
  orientation = "vertical",
  ...props
}: React.ComponentProps<typeof ScrollAreaPrimitive.ScrollAreaScrollbar>) {
  return (
    <ScrollAreaPrimitive.ScrollAreaScrollbar
      data-slot="scroll-area-scrollbar"
      orientation={orientation}
      className={cn(
        "flex touch-none p-px transition-colors select-none",
        orientation === "vertical" &&
          "h-full w-2.5 border-l border-l-transparent",
        orientation === "horizontal" &&
          "h-2.5 flex-col border-t border-t-transparent",
        className
      )}
      {...props}
    >
      <ScrollAreaPrimitive.ScrollAreaThumb
        data-slot="scroll-area-thumb"
        className="relative flex-1 rounded-full bg-border"
      />
    </ScrollAreaPrimitive.ScrollAreaScrollbar>
  )
}

export { ScrollArea, ScrollBar }
