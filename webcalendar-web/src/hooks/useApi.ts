import { useQuery, useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';

/**
 * Generic wrapper around useQuery for openapi-fetch results.
 *
 * @param queryKey - React Query cache key
 * @param fetcher - Async function that calls the API (should return { data, error })
 */
export function useApiQuery<TData>(
  queryKey: QueryKey,
  fetcher: () => Promise<{ data: TData | undefined; error: unknown }>,
) {
  return useQuery({
    queryKey,
    queryFn: async () => {
      const result = await fetcher();
      if (result.error) {
        throw result.error;
      }
      return result.data;
    },
  });
}

/**
 * Generic wrapper around useMutation for openapi-fetch results.
 *
 * @param mutationFn - Async function that calls the API
 * @param invalidateKeys - Query keys to invalidate on success
 */
export function useApiMutation<TData, TVariables>(
  mutationFn: (variables: TVariables) => Promise<{ data: TData | undefined; error: unknown }>,
  invalidateKeys?: QueryKey[],
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (variables: TVariables) => {
      const result = await mutationFn(variables);
      if (result.error) {
        throw result.error;
      }
      return result.data;
    },
    onSuccess: () => {
      if (invalidateKeys) {
        for (const key of invalidateKeys) {
          void queryClient.invalidateQueries({ queryKey: key });
        }
      }
    },
  });
}
