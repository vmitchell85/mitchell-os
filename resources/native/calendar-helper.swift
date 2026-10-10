// Reads calendars and events from macOS Calendar (EventKit) and prints JSON,
// so the app can use the accounts already synced to this Mac, such as Google.
// Read-only. `php artisan calendar:install` builds it into a small background
// app, launched with `open`, so macOS asks for calendar access on its behalf.
//
//   calendar-helper calendars
//   calendar-helper events <from ISO 8601> <to ISO 8601> <calendar id>[,<calendar id>...]

import EventKit
import Foundation

func fail(_ message: String, code: Int32 = 1) -> Never {
    FileHandle.standardError.write(message.data(using: .utf8)!)
    exit(code)
}

func printJSON(_ value: Any) {
    let data = try! JSONSerialization.data(withJSONObject: value, options: [.sortedKeys])
    FileHandle.standardOutput.write(data)
}

func hex(_ color: CGColor?) -> String? {
    guard let rgb = color?.converted(to: CGColorSpace(name: CGColorSpace.sRGB)!, intent: .defaultIntent, options: nil),
          let parts = rgb.components, parts.count >= 3 else { return nil }
    return String(format: "#%02X%02X%02X", Int(parts[0] * 255), Int(parts[1] * 255), Int(parts[2] * 255))
}

let store = EKEventStore()
let arguments = CommandLine.arguments

switch EKEventStore.authorizationStatus(for: .event) {
case .fullAccess:
    break
case .notDetermined:
    let semaphore = DispatchSemaphore(value: 0)
    var granted = false
    store.requestFullAccessToEvents { allowed, _ in
        granted = allowed
        semaphore.signal()
    }
    semaphore.wait()
    if !granted {
        fail("Calendar access was not allowed.", code: 2)
    }
default:
    fail("Calendar access is turned off. Allow it in System Settings > Privacy & Security > Calendars.", code: 2)
}

let local = ISO8601DateFormatter()
local.timeZone = TimeZone.current
local.formatOptions = [.withInternetDateTime]

let day = DateFormatter()
day.dateFormat = "yyyy-MM-dd"
day.timeZone = TimeZone.current

switch arguments.count > 1 ? arguments[1] : "" {
case "calendars":
    printJSON(store.calendars(for: .event).map { calendar in
        [
            "id": calendar.calendarIdentifier,
            "title": calendar.title,
            "account": calendar.source?.title ?? "",
            "color": hex(calendar.cgColor) as Any,
        ]
    })

case "events":
    guard arguments.count == 5,
          let from = ISO8601DateFormatter().date(from: arguments[2]),
          let to = ISO8601DateFormatter().date(from: arguments[3]) else {
        fail("Usage: calendar-helper events <from> <to> <calendar ids>")
    }
    let ids = Set(arguments[4].split(separator: ",").map(String.init))
    let calendars = store.calendars(for: .event).filter { ids.contains($0.calendarIdentifier) }
    if calendars.isEmpty {
        printJSON([])
        exit(0)
    }
    let events = store.events(matching: store.predicateForEvents(withStart: from, end: to, calendars: calendars))
    printJSON(events.sorted { $0.startDate < $1.startDate }.map { event in
        [
            "calendar_id": event.calendar.calendarIdentifier,
            "title": event.title ?? "",
            "all_day": event.isAllDay,
            // All-day events end at the start of the day after, so report their last day.
            "start": event.isAllDay ? day.string(from: event.startDate) : local.string(from: event.startDate),
            "end": event.isAllDay ? day.string(from: event.endDate.addingTimeInterval(-1)) : local.string(from: event.endDate),
            "location": event.location as Any,
        ]
    })

default:
    fail("Usage: calendar-helper calendars | events <from> <to> <calendar ids>")
}
