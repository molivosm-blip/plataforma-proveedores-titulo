import { useCallback, useState } from 'react';

type SetValue<T> = T | ((value: T) => T);

const isEqual = (a: unknown, b: unknown): boolean => {
  try {
    return JSON.stringify(a) === JSON.stringify(b);
  } catch {
    return a === b;
  }
};

export function useLocalStorage<T>(
  key: string,
  initialValue: T
): [T, (value: SetValue<T>) => void] {
  const readValue = useCallback((): T => {
    if (typeof window === 'undefined') {
      return initialValue;
    }

    try {
      const item = window.localStorage.getItem(key);

      if (!item) {
        return initialValue;
      }

      return JSON.parse(item) as T;
    } catch {
      return initialValue;
    }
  }, [key, initialValue]);

  const [storedValue, setStoredValue] = useState<T>(() => readValue());

  const setValue = useCallback(
    (value: SetValue<T>) => {
      setStoredValue(currentValue => {
        const newValue =
          value instanceof Function ? value(currentValue) : value;

        if (isEqual(currentValue, newValue)) {
          return currentValue;
        }

        try {
          if (typeof window !== 'undefined') {
            window.localStorage.setItem(key, JSON.stringify(newValue));
          }
        } catch {
          // Evita romper la app si localStorage falla
        }

        return newValue;
      });
    },
    [key]
  );

  return [storedValue, setValue];
}