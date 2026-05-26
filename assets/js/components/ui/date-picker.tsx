import * as React from "react"
import { format, parse, isValid } from "date-fns"
import { Calendar as CalendarIcon, Clock } from "lucide-react"
import { DateRange } from "react-day-picker"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"

interface DatePickerProps {
  date?: Date | DateRange
  onSelect?: (date: Date | DateRange | undefined) => void
  disabled?: boolean
  placeholder?: string
  className?: string
  includeTime?: boolean
  mode?: "single" | "range"
  numberOfMonths?: number
}

export function DatePicker({
  date,
  onSelect,
  disabled,
  placeholder = "Pick a date",
  className,
  includeTime = false,
  mode = "single",
  numberOfMonths = 1,
}: DatePickerProps) {
  const [open, _setOpen] = React.useState(false)
  const [selectedDate, setSelectedDate] = React.useState<Date | undefined>(
    date && !('from' in date) ? date : undefined
  )
  const [selectedRange, setSelectedRange] = React.useState<DateRange | undefined>(
    date && 'from' in date ? date : undefined
  )
  const [time, setTime] = React.useState<string>("")

  // custom setter that ignores close request until selection complete
  const setOpen = (next: boolean) => {
    if (!next) {
      // If we are in range mode and range not finished, keep open
      if (mode === 'range' && (!selectedRange || !selectedRange.to)) {
        return;
      }
      // If time picker is active and time not set, keep open
      if (includeTime && !time) {
        return;
      }
    }
    _setOpen(next);
  };

  React.useEffect(() => {
    if (date) {
      if ('from' in date) {
        setSelectedRange(date)
        if (includeTime && date.from) {
          setTime(format(date.from, "HH:mm"))
        }
      } else {
        setSelectedDate(date)
        if (includeTime) {
          setTime(format(date, "HH:mm"))
        }
      }
    }
  }, [date, includeTime])

  const handleDateSelect = (newDate: Date | undefined) => {
    if (!newDate) {
      setSelectedDate(undefined)
      setTime("")
      onSelect?.(undefined)
      return
    }

    let finalDate = newDate
    if (includeTime && time) {
      try {
        const parsedTime = parse(time, "HH:mm", newDate)
        if (isValid(parsedTime)) {
          finalDate = new Date(
            newDate.getFullYear(),
            newDate.getMonth(),
            newDate.getDate(),
            parsedTime.getHours(),
            parsedTime.getMinutes()
          )
        }
      } catch {
        // If time parsing fails, use the date without time
      }
    }

    setSelectedDate(finalDate)
    onSelect?.(finalDate)

    // Close popover immediately if no time picker and mode is single
    if (!includeTime) {
      setOpen(false)
    }
  }

  const handleRangeSelect = (range: DateRange | undefined) => {
    setSelectedRange(range)
    if (range?.from) {
      let finalRange = range
      if (includeTime && time) {
        try {
          const parsedTime = parse(time, "HH:mm", range.from)
          if (isValid(parsedTime)) {
            const fromDate = new Date(
              range.from.getFullYear(),
              range.from.getMonth(),
              range.from.getDate(),
              parsedTime.getHours(),
              parsedTime.getMinutes()
            )
            const toDate = range.to ? new Date(
              range.to.getFullYear(),
              range.to.getMonth(),
              range.to.getDate(),
              parsedTime.getHours(),
              parsedTime.getMinutes()
            ) : undefined
            finalRange = { from: fromDate, to: toDate }
          }
        } catch {
          // If time parsing fails, use the range without time
        }
      }
      onSelect?.(finalRange)

      // Keep popover open after selecting start date in range mode until 'to' is picked
      if (mode === 'range' && (!range.to)) {
        _setOpen(true)
      }

      // Close popover only when range is fully selected and no time picker
      if (range.to && !includeTime) {
        _setOpen(false)
      }
    }
  }

  const handleTimeChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newTime = e.target.value
    setTime(newTime)

    if (selectedDate && newTime) {
      try {
        const parsedTime = parse(newTime, "HH:mm", selectedDate)
        if (isValid(parsedTime)) {
          const newDate = new Date(
            selectedDate.getFullYear(),
            selectedDate.getMonth(),
            selectedDate.getDate(),
            parsedTime.getHours(),
            parsedTime.getMinutes()
          )
          setSelectedDate(newDate)
          onSelect?.(newDate)
        }
      } catch {
        // If time parsing fails, keep the existing date
      }
    } else if (selectedRange?.from && newTime) {
      try {
        const parsedTime = parse(newTime, "HH:mm", selectedRange.from)
        if (isValid(parsedTime)) {
          const fromDate = new Date(
            selectedRange.from.getFullYear(),
            selectedRange.from.getMonth(),
            selectedRange.from.getDate(),
            parsedTime.getHours(),
            parsedTime.getMinutes()
          )
          const toDate = selectedRange.to ? new Date(
            selectedRange.to.getFullYear(),
            selectedRange.to.getMonth(),
            selectedRange.to.getDate(),
            parsedTime.getHours(),
            parsedTime.getMinutes()
          ) : undefined
          const newRange = { from: fromDate, to: toDate }
          setSelectedRange(newRange)
          onSelect?.(newRange)
        }
      } catch {
        // If time parsing fails, keep the existing range
      }
    }
  }

  const formatDisplayDate = (date: Date | undefined) => {
    if (!date) return ""
    return date.toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      ...(includeTime && {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
      })
    })
  }

  const formatDisplayRange = (range: DateRange | undefined) => {
    if (!range?.from) return ""
    const from = formatDisplayDate(range.from)
    const to = range.to ? formatDisplayDate(range.to) : ""
    return to ? `${from} - ${to}` : from
  }

  return (
    <div className="grid gap-2">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            variant={"outline"}
            className={cn(
              "w-full justify-start text-left font-normal",
              !selectedDate && !selectedRange && "text-muted-foreground",
              className
            )}
            disabled={disabled}
          >
            <CalendarIcon className="mr-2 h-4 w-4" />
            {mode === "single" ? (
              selectedDate ? (
                <span>{formatDisplayDate(selectedDate)}</span>
              ) : (
                <span>{placeholder}</span>
              )
            ) : (
              selectedRange ? (
                <span>{formatDisplayRange(selectedRange)}</span>
              ) : (
                <span>{placeholder}</span>
              )
            )}
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-auto p-0" align="start">
          <div className="p-3">
            {mode === "single" ? (
              <Calendar
                mode="single"
                selected={selectedDate}
                onSelect={handleDateSelect}
                disabled={disabled}
                numberOfMonths={numberOfMonths}
                captionLayout="dropdown"
              />
            ) : (
              <Calendar
                mode="range"
                selected={selectedRange}
                onSelect={handleRangeSelect}
                disabled={disabled}
                numberOfMonths={numberOfMonths}
                captionLayout="dropdown"
              />
            )}
            {includeTime && (
              <div className="mt-3 flex items-center gap-2 border-t pt-3">
                <Clock className="h-4 w-4 text-muted-foreground" />
                <Input
                  type="time"
                  value={time}
                  onChange={handleTimeChange}
                  disabled={disabled}
                  className="h-8"
                  step="60"
                />
              </div>
            )}
          </div>
        </PopoverContent>
      </Popover>
    </div>
  )
} 